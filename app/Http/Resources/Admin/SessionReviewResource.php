<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\InterviewSession;
use App\Models\InterviewSessionLlmUsage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One interview session, reviewed (C11).
 *
 * Carries the evidence BEAI has always collected and never shown: timing, the
 * proctoring timeline with its weighted score, the timed snapshot strip, and
 * the cost estimate.
 *
 * The score arrives ALONGSIDE its events, never instead of them. A band is an
 * input to an operator's judgement about a candidate, and a judgement that
 * cannot be checked against what produced it is not a judgement, it is a
 * verdict.
 *
 * By the same reasoning, `integrity.band` is NULLABLE (proctoring-honest-coverage
 * AD-2). When a detector reported itself unavailable and the measured score is
 * below the medium threshold, there is no honest band to give: "low" would be an
 * assertion about a candidate drawn from observations nobody made. `null` means
 * "no opinion", and a read surface MUST render it as not-measured — never fall
 * back to the most flattering available value. `integrity.coverage_complete` and
 * `integrity.unavailable_layers` say why.
 *
 * Snapshots carry signed, expiring URLs. `s3_key` never leaves the server:
 * returning it would imply either a public bucket of identifiable webcam
 * frames or a disclosed storage layout.
 *
 * @mixin InterviewSession
 */
final class SessionReviewResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $integrity
     * @param  list<array{url: string, taken_at: string}>  $snapshots
     * @param  array{provider: string, minutes: float, usd: float}|null  $avatarCost
     * @param  array<string, mixed>|null  $evaluation
     */
    public function __construct(
        InterviewSession $session,
        private readonly array $integrity,
        private readonly array $snapshots,
        private readonly ?array $avatarCost,
        private readonly ?InterviewSessionLlmUsage $llmUsage,
        private readonly ?array $evaluation = null,
    ) {
        parent::__construct($session);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'participant_id' => $this->participant_id,
            'competency_code' => $this->competency_code,
            'question_index' => $this->question_index,
            'provider' => $this->provider,
            'provider_session_ref' => $this->provider_session_ref,
            'status' => $this->status,
            'ended_reason' => $this->ended_reason,
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'duration_seconds' => $this->durationSeconds(),
            'integrity' => $this->integrity,
            'snapshots' => $this->snapshots,
            // TWO SEPARATE labelled lines, never one combined total — the
            // same refusal already ratified at `SessionCostEstimator.php:20-22`
            // for avatar-vs-LLM spend: different vendors, different meters.
            //
            // `avatar`: minutes only. `ai_requests` has no interview_session_id,
            // so LLM spend cannot be attributed to one session without
            // inventing the link — and a plausible number with no basis is
            // worse than an absent one (D5).
            //
            // `llm`: null when the session was never billed (unbound/degraded —
            // no vendor default is priced). When present, `actual_usd`
            // renders ONLY when non-null (pluggable-conversation-llm PR P6b,
            // design D5: permanently null in managed mode, reserved for a
            // future native_duplex change).
            'cost' => [
                'avatar' => $this->avatarCost,
                'llm' => $this->llmCostLine(),
                'is_estimate' => true,
            ],
            // The BARS evidence for the competency THIS session probed, so the
            // backoffice needs ONE request rather than a second fetch it has to
            // correlate itself — client-side correlation is where a wrong
            // excerpt/session pairing would be introduced.
            //
            // `null` — not an empty object — when the participant has not
            // reached `completato` (the Evaluation read gate), when there is no
            // evaluation, or when this competency was never scored. All three
            // mean "no evidence to show", and a caller that renders a section
            // only for a non-null block cannot accidentally display an empty
            // one as though it were a verdict.
            //
            // Each behaviour carries `excerpts_spoken_in_this_session`, a
            // positional parallel of `excerpts`. It is NOT decoration: the
            // scoring corpus spans the participant's whole interview
            // (`TranscriptAssembler`), so an excerpt shown on a session page may
            // legitimately quote a different session, and the flag is what stops
            // this surface from implying otherwise. See `SessionEvidenceReader`.
            'evaluation' => $this->evaluationBlock(),
        ];
    }

    /**
     * Typed so Scramble emits a real schema rather than a bare `{}` — the
     * backoffice generates its client from `openapi.json`, and an untyped
     * property arrives there as `unknown`, which is exactly the shape that
     * invites hand-written correlation code on the client.
     *
     * @return array{
     *     competency_code: string,
     *     score: float|null,
     *     reliability: string,
     *     behaviors: array<int, array{indicator: string, score: int|null, explanation: string, excerpts: array<int, string>, excerpts_spoken_in_this_session: array<int, bool>, unassessable_reason: string|null}>,
     *     unscorable_reason: string|null
     * }|null
     *
     * @scramble-return array{
     *     competency_code: string,
     *     score: float|null,
     *     reliability: string,
     *     behaviors: array<int, array{indicator: string, score: int|null, explanation: string, excerpts: array<int, string>, excerpts_spoken_in_this_session: array<int, bool>, unassessable_reason: string|null}>,
     *     unscorable_reason: string|null
     * }|null
     */
    private function evaluationBlock(): ?array
    {
        /** @var array{competency_code: string, score: float|null, reliability: string, behaviors: array<int, array{indicator: string, score: int|null, explanation: string, excerpts: array<int, string>, excerpts_spoken_in_this_session: array<int, bool>, unassessable_reason: string|null}>, unscorable_reason: string|null}|null $evaluation */
        $evaluation = $this->evaluation;

        return $evaluation;
    }

    /**
     * @return array{estimated_usd: float|null, actual_usd: float|null}|null
     */
    private function llmCostLine(): ?array
    {
        if ($this->llmUsage === null) {
            return null;
        }

        return [
            'estimated_usd' => $this->llmUsage->estimated_cost_usd === null
                ? null
                : (float) $this->llmUsage->estimated_cost_usd,
            'actual_usd' => $this->llmUsage->actual_cost_usd === null
                ? null
                : (float) $this->llmUsage->actual_cost_usd,
        ];
    }

    private function durationSeconds(): ?int
    {
        // (interview-session-started-at, D3) Accumulated LIVE time, never
        // the wall-clock span. The caller MUST eager-load `livePeriods`.
        return $this->liveSeconds();
    }
}
