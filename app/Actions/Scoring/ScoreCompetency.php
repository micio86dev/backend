<?php

declare(strict_types=1);

namespace App\Actions\Scoring;

use App\Contracts\LLMProvider;
use App\DTOs\LLMResponse;
use App\DTOs\Scoring\IndicatorRef;
use App\DTOs\Scoring\IndicatorScoreDTO;
use App\Enums\AiRequestFailureReason;
use App\Enums\IndicatorFailureReason;
use App\Enums\Scoring\ScoringDisposition;
use App\Enums\Scoring\ScoringFailure;
use App\Enums\UnscorableReason;
use App\Exceptions\Scoring\AnchorTranslationMissingException;
use App\Exceptions\Scoring\ExcerptNotVerbatimException;
use App\Exceptions\Scoring\IndicatorCountMismatchException;
use App\Exceptions\Scoring\InvalidIndicatorScoreException;
use App\Exceptions\Scoring\JsonParseException;
use App\Exceptions\Scoring\RoleNoBarsException;
use App\Models\AiRequest;
use App\Models\BarsIndicator;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\IndicatorScore;
use App\Models\InterviewSession;
use App\Services\Scoring\Contracts\ReliabilityStrategy;
use App\Services\Scoring\Contracts\ValidityPredicate;
use App\Services\Scoring\EvaluationParser;
use App\Services\Scoring\ExcerptValidator;
use App\Services\Scoring\IndicatorValidator;
use App\Services\Scoring\MeanCalculator;
use App\Services\Scoring\PromptBuilder;
use App\Services\Scoring\ResponseEnvelopeStripper;
use App\Services\Scoring\ScoringFailureClassifier;
use App\Services\Scoring\TranscriptAssembler;
use App\Support\Observability\AiRequestCostEstimator;
use App\Support\Observability\ResponseFingerprint;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ScoreCompetency — score a single competency: assemble transcript → build prompt →
 * call LLM → parse → validate → persist in one transaction (split-score-evaluation-job).
 *
 * MOVED verbatim out of `App\Jobs\ScoreEvaluationJob::scoreCompetency()` (design.md D1)
 * — zero behavior change, only `$this->participantId` became an explicit parameter
 * since this class carries no job-instance state. `persistUnscorable()` and
 * `recordAiRequest()` moved alongside it: both are private collaborators called only
 * from within this method, on the original class as on this one.
 *
 * Error handling:
 * - RoleNoBarsException → CompetencyResult(unscorable_reason=role_no_bars), no LLM call.
 * - AnchorTranslationMissingException → CompetencyResult(anchor_translation_missing), no LLM call.
 * - Parsing/validation errors → CompetencyResult(llm_parse_error), no queue retry.
 *
 * @throws UniqueConstraintViolationException When CompetencyResult INSERT races (caught by the caller, CW5).
 */
final class ScoreCompetency
{
    /**
     * @param  Collection<int, BarsIndicator>  $indicators
     */
    public function score(
        Evaluation $evaluation,
        string $competencyCode,
        int $competencyId,
        string $projectLocale,
        Collection $indicators,
        InterviewSession $session,
        TranscriptAssembler $transcriptAssembler,
        PromptBuilder $promptBuilder,
        LLMProvider $llmProvider,
        EvaluationParser $evaluationParser,
        IndicatorValidator $indicatorValidator,
        ExcerptValidator $excerptValidator,
        MeanCalculator $meanCalculator,
        ReliabilityStrategy $reliabilityStrategy,
        ValidityPredicate $validityPredicate,
        int $participantId,
    ): void {
        // ── Handle: no BARS catalog (role_no_bars) ───────────────────────
        if ($indicators->isEmpty()) {
            Log::info('ScoreEvaluationJob: no BARS indicators — role_no_bars', [
                'evaluation_id' => $evaluation->id,
                'competency_code' => $competencyCode,
            ]);

            $this->persistUnscorable(
                evaluation: $evaluation,
                competencyCode: $competencyCode,
                reason: UnscorableReason::RoleNoBars,
            );

            return;
        }

        // ── Assemble the two corpora (evaluator-evidence-and-rigor D-1) ──
        // The prompt corpus spans the WHOLE interview with this competency's
        // segment delimited; the validation corpus is candidate speech only.
        // $session above is still the "does this competency have a session at
        // all" gate — it just no longer bounds the evidence.
        $corpora = $transcriptAssembler->assembleForParticipant(
            participantId: $participantId,
            targetCompetencyCode: $competencyCode,
        );
        $transcript = $corpora->prompt;
        $validationCorpus = $corpora->validation;

        // ── Build prompt (triggers AnchorTranslationMissingException on L-2 fail) ──
        try {
            $promptPayload = $promptBuilder->build(
                evaluation: $evaluation,
                competencyCode: $competencyCode,
                competencyId: $competencyId,
                roleId: 0, // role_id not needed; PromptBuilder uses the $indicators collection
                projectLocale: $projectLocale,
                indicators: $indicators,
                transcript: $transcript,
            );
        } catch (AnchorTranslationMissingException $e) {
            Log::info('ScoreEvaluationJob: anchor translation missing — anchor_translation_missing', [
                'evaluation_id' => $evaluation->id,
                'competency_code' => $competencyCode,
                'error' => $e->getMessage(),
            ]);

            $this->persistUnscorable(
                evaluation: $evaluation,
                competencyCode: $competencyCode,
                reason: UnscorableReason::AnchorTranslationMissing,
            );

            return;
        } catch (RoleNoBarsException) {
            $this->persistUnscorable($evaluation, $competencyCode, UnscorableReason::RoleNoBars);

            return;
        }

        // ── Call LLM ─────────────────────────────────────────────────────
        // Pass competency_code in options so CassetteLLMProvider can look it up.
        $options = array_merge($promptPayload->options, [
            'competency_code' => $competencyCode,
        ]);

        $fullPrompt = $promptPayload->systemPrompt."\n\n".$promptPayload->userMessage;

        $startMs = (int) round(microtime(true) * 1000);
        $llmResponse = $llmProvider->complete($fullPrompt, $options);
        $latencyMs = (int) round(microtime(true) * 1000) - $startMs;

        // ── Truncation short-circuit + retry loop — BEFORE any parse attempt (D3/D4/D8) ──
        //
        // A truncated body is not a parse failure that happens to be caused by
        // truncation — it is a different fact about a different actor, and
        // routing it through json_decode() is what made the 2026-08-24
        // incident undiagnosable. Each attempt — including a retry — is
        // recorded with its OWN ai_requests row BEFORE its outcome is known to
        // be terminal: every call is made and billed (C13 D1 / D8), and a
        // retry that reused the first row would hide a real cost at exactly
        // the moment something is already wrong.
        //
        // The loop is driven entirely by ScoringFailureClassifier — the only
        // line that grants a retry is the `RetryWithLargerBudget` arm of its
        // match, reachable only for ScoringFailure::ResponseTruncated. With no
        // `scoring.truncation_retry` config (or `enabled: false`),
        // maxAttempts() returns 0 and this loop body runs at most once,
        // identical to Increment A's behavior.
        $attemptsAlreadyMade = 0;

        while ($llmResponse->truncated) {
            Log::warning('ScoreEvaluationJob: truncated response — llm_truncated', [
                'evaluation_id' => $evaluation->id,
                'competency_code' => $competencyCode,
                'finish_reason' => $llmResponse->finishReason,
                'attempts_already_made' => $attemptsAlreadyMade,
            ]);

            // The call was MADE and BILLED — record it before finalizing or
            // retrying, exactly like every other billed-but-unusable call
            // (C13 D1 / D8).
            $this->recordAiRequest(
                $evaluation,
                $competencyCode,
                $llmResponse,
                $latencyMs,
                $options,
                success: false,
                failureReason: AiRequestFailureReason::Truncated,
            );

            $disposition = (new ScoringFailureClassifier)->classify(
                ScoringFailure::ResponseTruncated,
                attemptsAlreadyMade: $attemptsAlreadyMade,
            );

            if ($disposition === ScoringDisposition::Terminal) {
                $this->persistUnscorable($evaluation, $competencyCode, UnscorableReason::LlmTruncated);

                return;
            }

            // ── Retry: same call, enlarged (config-driven) max_tokens budget (D8) ──
            $currentMaxTokens = (int) ($options['max_tokens'] ?? config('scoring.anthropic.max_tokens', 2048));
            $multiplier = (float) config('scoring.truncation_retry.budget_multiplier', 2.0);
            $ceiling = (int) config('scoring.truncation_retry.budget_ceiling', 8192);

            $options = array_merge($options, [
                'max_tokens' => min((int) round($currentMaxTokens * $multiplier), $ceiling),
            ]);

            $attemptsAlreadyMade++;

            $startMs = (int) round(microtime(true) * 1000);
            $llmResponse = $llmProvider->complete($fullPrompt, $options);
            $latencyMs = (int) round(microtime(true) * 1000) - $startMs;
        }

        // ── Parse + validate ──────────────────────────────────────────────
        // Convert BarsIndicator collection to IndicatorRefs for the parser.
        // indicator_text is cast to string; position is already int from the model.
        // array_values() ensures the result is a list (contiguous int keys starting at 0).
        /** @var list<IndicatorRef> $indicatorList */
        $indicatorList = array_values(
            $indicators->map(
                static fn (BarsIndicator $ind): IndicatorRef => new IndicatorRef(
                    position: (int) $ind->position,
                    text: (string) $ind->getTranslation('text', $projectLocale),
                )
            )->all()
        );

        // ── Phase 1: ENVELOPE. May throw. Nothing to isolate yet — no DTOs exist (D7) ──
        try {
            $dtos = $evaluationParser->parse($llmResponse->content, $indicatorList);
        } catch (JsonParseException|IndicatorCountMismatchException $e) {
            Log::warning('ScoreEvaluationJob: parse error — llm_parse_error', [
                'evaluation_id' => $evaluation->id,
                'competency_code' => $competencyCode,
                'error' => $e->getMessage(),
            ]);

            // C13: the call above was MADE and BILLED. Returning without a row
            // here is how four classes of paid failure left no trace at all.
            // The reason is a machine key, never $e->getMessage(): a provider
            // error can echo prompt content, and prompts contain candidate
            // answers.
            $this->recordAiRequest(
                $evaluation,
                $competencyCode,
                $llmResponse,
                $latencyMs,
                $options,
                success: false,
                failureReason: $e instanceof JsonParseException
                    ? AiRequestFailureReason::ParseError
                    : AiRequestFailureReason::IndicatorCountMismatch,
            );

            // DO NOT queue-retry: persist as llm_parse_error immediately (D4 FIX-9).
            $this->persistUnscorable($evaluation, $competencyCode, UnscorableReason::LlmParseError);

            return;
        }

        // ── Phase 2: PER-INDICATOR. The `try` is INSIDE the loop (D7) ──────
        //
        // An indicator that fails validation (illegal score, or non-verbatim
        // excerpt) is marked unassessable and its sibling DTOs in the SAME
        // competency — whether validated before or after the failing one in
        // processing order — retain their own scores. This is what makes
        // isolation a consequence of the catch SCOPE, not a convention.
        $validated = [];

        foreach ($dtos as $dto) {
            try {
                $indicatorValidator->validate($dto);
                // Candidate-only corpus, NOT the prompt corpus: the model may
                // read the interviewer's questions but may not cite them as
                // evidence that the candidate did anything (D-1).
                $excerptValidator->validate($dto, $validationCorpus);
                $validated[] = $dto;
            } catch (InvalidIndicatorScoreException) {
                $validated[] = $dto->asUnassessable(IndicatorFailureReason::ScoreIllegal);
            } catch (ExcerptNotVerbatimException) {
                $validated[] = $dto->asUnassessable(IndicatorFailureReason::ExcerptUnverifiable);
            }
        }

        // Post-condition (D7/D9): the loop appends exactly once on every path
        // (try-success, and both catch arms) — no sibling is ever silently
        // dropped. Checked here AND asserted explicitly by
        // tests/Feature/Jobs/PerIndicatorIsolationTest.php.
        assert(count($validated) === count($dtos));

        // ── Compute mean + reliability + validity (PR3: real values) ─────
        $scores = array_map(static fn (IndicatorScoreDTO $dto): int => $dto->score, $validated);
        $score = $meanCalculator->compute($scores);
        $reliability = $reliabilityStrategy->compute($scores);
        $valid = $validityPredicate->isValid($reliability);

        // ── Record the billed call BEFORE the results transaction (C13 D1) ──
        //
        // This deliberately REVERSES C9's D2 CW, which required the ai_requests
        // row and the CompetencyResult INSERT to share a transaction. A provider
        // call is external, irreversible and billed; the results are local and
        // revocable. Nesting the first inside the second meant any later failure
        // in that transaction erased the record of money already spent — and it
        // failed in the direction that HIDES cost, at exactly the moment
        // something else had gone wrong.
        //
        // Safe on C9's own terms: that design states the resume-skip signal is
        // the CompetencyResult row ("do NOT use the presence of an ai_requests
        // row as a skip signal") and that an extra ai_requests row is "valid
        // audit data", not an anomaly. Nothing depended on the coupling.
        $this->recordAiRequest(
            $evaluation,
            $competencyCode,
            $llmResponse,
            $latencyMs,
            $options,
            success: true,
            failureReason: null,
        );

        // ── Persist: CompetencyResult + IndicatorScores in ONE transaction ──
        DB::transaction(function () use (
            $evaluation, $competencyCode, $score, $reliability, $valid, $validated
        ): void {
            // Persist CompetencyResult with real reliability + valid (PR3).
            $cr = CompetencyResult::create([
                'evaluation_id' => $evaluation->id,
                'competency_code' => $competencyCode,
                'score' => $score,
                'reliability' => $reliability,
                'valid' => $valid,
                'unscorable_reason' => null,
            ]);

            // 3. Persist IndicatorScore rows.
            //
            // unassessable_reason (B2a/B2b, D1/D7): every -1 score is tagged
            // with why — ModelDeclared / ScoreIllegal from the parser
            // (EvaluationParser::parse()), or ScoreIllegal / ExcerptUnverifiable
            // from this method's per-indicator catch above (asUnassessable()) —
            // so this is a straight passthrough of $dto->unassessableReason.
            // Required by indicator_scores_unassessable_reason_check — every
            // -1 row MUST carry a non-null reason.
            foreach ($validated as $dto) {
                IndicatorScore::create([
                    'competency_result_id' => $cr->id,
                    'indicator_text' => $dto->indicatorText,
                    'score' => $dto->score,
                    'explanation' => $dto->explanation,
                    'excerpts' => $dto->excerpts,
                    'position' => $dto->position,
                    'unassessable_reason' => $dto->unassessableReason?->value,
                ]);
            }
        });

        Log::info('ScoreEvaluationJob: competency scored', [
            'evaluation_id' => $evaluation->id,
            'competency_code' => $competencyCode,
            'score' => $score,
            'reliability' => $reliability,
            'valid' => $valid,
        ]);
    }

    /**
     * Persist a CompetencyResult as unscorable (no LLM call, no ai_requests row).
     *
     * `$reason` is `UnscorableReason` (D2) — an enum in PHP, a plain string in
     * Postgres. No Eloquent cast, no CHECK constraint: only `->value` crosses
     * into the write.
     */
    private function persistUnscorable(
        Evaluation $evaluation,
        string $competencyCode,
        UnscorableReason $reason,
    ): void {
        try {
            CompetencyResult::create([
                'evaluation_id' => $evaluation->id,
                'competency_code' => $competencyCode,
                'score' => null,
                'reliability' => 0.0,
                'valid' => false,
                'unscorable_reason' => $reason->value,
            ]);
        } catch (UniqueConstraintViolationException) {
            // CW5: already persisted (race condition) → skip.
            Log::warning('ScoreEvaluationJob: unscorable CompetencyResult already exists — skip', [
                'evaluation_id' => $evaluation->id,
                'competency_code' => $competencyCode,
            ]);
        }
    }

    /**
     * Append the cost record for one provider call (C13, observability delta).
     *
     * Called on EVERY path that follows a completed call — success and each
     * failure class alike — and deliberately never from inside a transaction
     * that persists scoring results. See the call sites and design D1.
     *
     * `estimated_cost_usd` is computed here, at write time, from the config
     * rate table. Computing it on read would let a later price change silently
     * rewrite history: last quarter's spend would move because this quarter's
     * rates did.
     *
     * @param  array<string, mixed>  $options
     */
    private function recordAiRequest(
        Evaluation $evaluation,
        string $competencyCode,
        LLMResponse $llmResponse,
        int $latencyMs,
        array $options,
        bool $success,
        ?AiRequestFailureReason $failureReason,
    ): void {
        $estimator = app(AiRequestCostEstimator::class);

        // D6 — derived signals only, for EVERY scoring call, success or
        // failure. The fingerprint's own types make it structurally unable
        // to carry the response content; see ResponseFingerprint's docblock.
        $fingerprint = ResponseFingerprint::from($llmResponse->content, new ResponseEnvelopeStripper);

        AiRequest::create([
            'evaluation_id' => $evaluation->id,
            'competency_code' => $competencyCode,
            'provider' => (string) config('scoring.provider', 'anthropic'),
            'model' => $llmResponse->model,
            'prompt_version' => $options['prompt_version'] ?? config('scoring.prompt_version'),
            'input_tokens' => $llmResponse->inputTokens,
            'output_tokens' => $llmResponse->outputTokens,
            'estimated_cost_usd' => $estimator->estimate(
                $llmResponse->model,
                $llmResponse->inputTokens,
                $llmResponse->outputTokens,
            ),
            'success' => $success,
            'failure_reason' => $failureReason?->value,
            'finish_reason' => $llmResponse->finishReason,
            'response_bytes' => $fingerprint->bytes,
            'response_fenced' => $fingerprint->fenced,
            'response_sha256' => $fingerprint->sha256,
            'latency_ms' => $latencyMs,
        ]);
    }
}
