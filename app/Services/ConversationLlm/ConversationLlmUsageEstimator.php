<?php

declare(strict_types=1);

namespace App\Services\ConversationLlm;

use App\Models\InterviewSession;
use App\Models\LlmModel;
use App\Models\Utterance;
use Illuminate\Support\Collection;

/**
 * `chars4_context_resend_v1` — the conversation-LLM cost estimator
 * (pluggable-conversation-llm PR P6b, design D10, non-negotiable #3).
 *
 * Indexing (stated unambiguously — design.md Gate Corrections B3: an
 * undifferentiated `u_i` is what hid the omission this formula had to be
 * corrected for):
 *
 *   t   — indexes AVATAR turns, t = 1..T, in transcript order.
 *   p_t — tokens of the PARTICIPANT run immediately preceding avatar turn t
 *         (the utterance that ELICITED it). 0 when there is none (the
 *         opening greeting).
 *   o_t — tokens of the AVATAR utterance at turn t.
 *   P   — tokens of the system prompt, re-sent on EVERY turn.
 *
 *   tokens(s) = ceil(mb_strlen(s) / 4)
 *
 *   c_t = P + Σ_{i<t}(p_i + o_i) + p_t
 *
 * The trailing `p_t` is REQUIRED: the participant's turn-t message IS the
 * input the model is responding to. Omitting it (`c_t = P + Σ_{i<t} u_i`)
 * under-counts every single turn by that turn's own eliciting utterance —
 * exactly the defect a fresh-context review caught in this change's first
 * revision (design.md Gate Corrections B3).
 *
 *   estimated_input_tokens  = Σ_{t∈G} c_t
 *   estimated_output_tokens = Σ_{t∈G} o_t
 *
 * `G` excludes the OPENING GREETING (composed server-side by
 * `OpeningTextComposer` — no LLM request was made for it): the first avatar
 * turn is excluded from `G` iff `p_1 = 0`. Its tokens still appear inside
 * every LATER `c_t`, because they are in the history the provider re-sends.
 *
 * THE TIER BRANCH is applied PER REQUEST, keyed on `c_t` — never on the
 * session total, and `rate_out` is ALSO keyed on `c_t` (Google tiers on
 * prompt size, not on how much a given request produced):
 *
 *   rate_in(c)  = c > threshold ? high_in  : low_in
 *   rate_out(c) = c > threshold ? high_out : low_out
 *   cost = Σ_{t∈G} ( c_t/1e6 * rate_in(c_t) + o_t/1e6 * rate_out(c_t) )
 *
 * REFUSAL, not coercion: a NULL rate column (design D1 — NULL means "Google
 * does not publish this", never zero) makes `estimated_cost_usd = null`. The
 * measured token counts are always kept; only the price is refused.
 */
final class ConversationLlmUsageEstimator
{
    public const METHOD = 'chars4_context_resend_v1';

    /**
     * `tokens(s) = ceil(mb_strlen(s) / 4)` — design D10.
     */
    public function tokensForChars(int $chars): int
    {
        return (int) ceil($chars / 4);
    }

    /**
     * The pure formula core, operating on TOKEN counts (not characters).
     * Tokenization is a separate, independently-testable concern (see
     * `estimate()` below, which is the DB-touching caller that converts
     * persisted `Utterance` rows into this shape).
     *
     * @param  list<array{participant_tokens: int, avatar_tokens: int}>  $turns  Ordered
     *                                                                           t = 1..T, one element per AVATAR turn (including the opening
     *                                                                           greeting, if any — identified by `participant_tokens === 0`
     *                                                                           on the FIRST element only).
     * @return array{estimated_input_tokens: int, estimated_output_tokens: int, estimated_cost_usd: float|null, missing_rate: string|null}
     */
    public function computeFromTokens(int $systemPromptTokens, array $turns, ?LlmModel $model): array
    {
        $priorSum = 0; // Σ_{i<t}(p_i + o_i), accumulated as we walk turns in order.
        $estimatedInput = 0;
        $estimatedOutput = 0;
        $cost = 0.0;
        $costRefused = $model === null;
        $missingRate = null;

        foreach ($turns as $index => $turn) {
            $pt = $turn['participant_tokens'];
            $ot = $turn['avatar_tokens'];

            // G excludes the opening greeting: the FIRST avatar turn, iff
            // no participant utterance preceded it. Its tokens still enter
            // $priorSum below, so later c_t values see it in history.
            $isOpeningGreeting = $index === 0 && $pt === 0;

            if (! $isOpeningGreeting) {
                $c = $systemPromptTokens + $priorSum + $pt;

                $estimatedInput += $c;
                $estimatedOutput += $ot;

                if ($model !== null && ! $costRefused) {
                    $rateIn = $this->resolveRate($model, $c, 'text_input_usd_per_million', 'text_input_usd_per_million_high');
                    $rateOut = $this->resolveRate($model, $c, 'text_output_usd_per_million', 'text_output_usd_per_million_high');

                    if ($rateIn === null || $rateOut === null) {
                        $costRefused = true;
                        $missingRate = $rateIn === null ? 'text_input_usd_per_million' : 'text_output_usd_per_million';
                    } else {
                        $cost += ($c / 1_000_000) * $rateIn + ($ot / 1_000_000) * $rateOut;
                    }
                }
            }

            $priorSum += $pt + $ot;
        }

        return [
            'estimated_input_tokens' => $estimatedInput,
            'estimated_output_tokens' => $estimatedOutput,
            'estimated_cost_usd' => $costRefused ? null : round($cost, 6),
            'missing_rate' => $missingRate,
        ];
    }

    /**
     * A per-template forecast: ONE total cost for a reference interview,
     * never a $/minute figure — see `config/conversation_llm.php`'s
     * `forecast` section for why a per-minute number would misstate cost at
     * any other interview length (input grows quadratically in turn count).
     *
     * Built from SYNTHETIC turns (config-driven reference character counts),
     * run through the SAME formula core as a real session's estimate — this
     * is not a second, cheaper approximation.
     */
    public function forecastCostUsd(?LlmModel $model): ?float
    {
        if ($model === null) {
            return null;
        }

        $turnCount = (int) config('conversation_llm.forecast.reference_turns');
        $participantChars = (int) config('conversation_llm.forecast.reference_participant_chars_per_turn');
        $avatarChars = (int) config('conversation_llm.forecast.reference_avatar_chars_per_turn');
        $systemPromptChars = (int) config('conversation_llm.forecast.reference_system_prompt_chars');

        $participantTokens = $this->tokensForChars($participantChars);
        $avatarTokens = $this->tokensForChars($avatarChars);

        $turns = array_fill(0, $turnCount, [
            'participant_tokens' => $participantTokens,
            'avatar_tokens' => $avatarTokens,
        ]);

        $computed = $this->computeFromTokens($this->tokensForChars($systemPromptChars), $turns, $model);

        return $computed['estimated_cost_usd'];
    }

    /**
     * The DB-touching caller: reads a session's persisted `utterances` +
     * `system_prompt_chars` + the matching `llm_models` row (by
     * `llm_model_key`), pairs them into avatar turns, and returns the
     * exact row shape `interview_session_llm_usage` persists.
     *
     * Called from BOTH `/end` and `beai:reconcile-llm-usage` — the SAME
     * computation, run at two different times, over the SAME persisted
     * inputs (design D10's reconciliation section).
     *
     * @return array{turn_count: int, system_prompt_chars: int|null, participant_chars: int, avatar_chars: int, live_seconds: int|null, estimated_input_tokens: int, estimated_output_tokens: int, estimated_cost_usd: float|null, estimation_method: string, rate_card: array<string, mixed>}
     */
    public function estimate(InterviewSession $session): array
    {
        $utterances = $session->utterances()->orderBy('ts')->orderBy('id')->get();
        $runs = $this->coalesceRuns($utterances);
        $turns = $this->pairTurns($runs);

        $participantChars = (int) $runs->where('speaker', 'candidate')->sum('chars');
        $avatarChars = (int) $runs->where('speaker', 'avatar')->sum('chars');

        $systemPromptCharsMissing = $session->system_prompt_chars === null;
        $systemPromptTokens = $systemPromptCharsMissing ? 0 : $this->tokensForChars($session->system_prompt_chars);

        $model = $session->llm_model_key === null
            ? null
            : LlmModel::where('key', $session->llm_model_key)->first();

        $tokenTurns = array_values($turns->map(fn (array $t): array => [
            'participant_tokens' => $this->tokensForChars($t['participant_chars']),
            'avatar_tokens' => $this->tokensForChars($t['avatar_chars']),
        ])->all());

        $computed = $this->computeFromTokens($systemPromptTokens, $tokenTurns, $model);

        // (design D5) The missing-prompt case: token counts are still
        // written (computed with P treated as 0 — the closest honest
        // approximation with no other source), but the PRICE is always
        // refused, regardless of whether every rate column was otherwise
        // available. Measured facts kept; the price refused, not guessed.
        $estimatedCost = $systemPromptCharsMissing ? null : $computed['estimated_cost_usd'];

        return [
            'turn_count' => $turns->count(),
            'system_prompt_chars' => $session->system_prompt_chars,
            'participant_chars' => $participantChars,
            'avatar_chars' => $avatarChars,
            'live_seconds' => $session->liveSeconds(),
            'estimated_input_tokens' => $computed['estimated_input_tokens'],
            'estimated_output_tokens' => $computed['estimated_output_tokens'],
            'estimated_cost_usd' => $estimatedCost,
            'estimation_method' => self::METHOD,
            'rate_card' => $this->rateCardSnapshot($model, $systemPromptCharsMissing, $computed['missing_rate']),
        ];
    }

    /**
     * Coalesce consecutive same-speaker utterance rows into one run,
     * mirroring `TranscriptAssembler`'s own case-insensitive speaker
     * doctrine and ordering (`orderBy('ts')->orderBy('id')`).
     *
     * @param  Collection<int, Utterance>  $utterances
     * @return Collection<int, array{speaker: string, chars: int}>
     */
    private function coalesceRuns(Collection $utterances): Collection
    {
        $runs = [];

        foreach ($utterances as $utterance) {
            $speaker = strtolower(trim((string) $utterance->speaker));
            $chars = mb_strlen((string) $utterance->text);

            $last = count($runs) - 1;

            if ($last >= 0 && $runs[$last]['speaker'] === $speaker) {
                $runs[$last]['chars'] += $chars;
            } else {
                $runs[] = ['speaker' => $speaker, 'chars' => $chars];
            }
        }

        return collect($runs);
    }

    /**
     * Pair coalesced runs into avatar turns: `p_t` is the immediately
     * preceding participant run's chars, or 0 when there is none (the
     * opening greeting, or — in a malformed transcript — two avatar runs
     * with nothing between them).
     *
     * @param  Collection<int, array{speaker: string, chars: int}>  $runs
     * @return Collection<int, array{participant_chars: int, avatar_chars: int}>
     */
    private function pairTurns(Collection $runs): Collection
    {
        $turns = [];
        $pendingParticipantChars = 0;

        foreach ($runs as $run) {
            if ($run['speaker'] === 'candidate') {
                $pendingParticipantChars += $run['chars'];

                continue;
            }

            if ($run['speaker'] === 'avatar') {
                $turns[] = [
                    'participant_chars' => $pendingParticipantChars,
                    'avatar_chars' => $run['chars'],
                ];
                $pendingParticipantChars = 0;
            }
        }

        return collect($turns);
    }

    private function resolveRate(LlmModel $model, int $c, string $lowColumn, string $highColumn): ?float
    {
        $threshold = $model->context_tier_threshold_tokens;
        $useHigh = $threshold !== null && $c > $threshold;
        $column = $useHigh ? $highColumn : $lowColumn;
        $value = $model->{$column};

        return $value === null ? null : (float) $value;
    }

    /**
     * The `rate_card` jsonb snapshot — what this row ACTUALLY charged, so a
     * later `llm_models` price edit can never change an already-stored cost.
     *
     * @return array<string, mixed>
     */
    private function rateCardSnapshot(?LlmModel $model, bool $systemPromptCharsMissing, ?string $missingRate): array
    {
        $reason = match (true) {
            $systemPromptCharsMissing => 'system_prompt_chars_missing',
            $model === null => 'model_not_found',
            $missingRate !== null => $missingRate,
            default => null,
        };

        return [
            'model_key' => $model?->key,
            'vendor' => $model?->vendor,
            'text_input_usd_per_million' => $model?->text_input_usd_per_million,
            'text_output_usd_per_million' => $model?->text_output_usd_per_million,
            'text_input_usd_per_million_high' => $model?->text_input_usd_per_million_high,
            'text_output_usd_per_million_high' => $model?->text_output_usd_per_million_high,
            'context_tier_threshold_tokens' => $model?->context_tier_threshold_tokens,
            'missing_reason' => $reason,
        ];
    }
}
