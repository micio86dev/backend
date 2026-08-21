<?php

declare(strict_types=1);

namespace App\Actions\InterviewSession;

use App\Models\InterviewSession;

/**
 * Return an errored competency to the candidate (interview-continuous-flow, D3).
 *
 * Extracted VERBATIM from `RecoverFailedParticipant`'s loop body so the two paths
 * that re-offer a competency share one definition rather than two that drift:
 *
 *   - AUTOMATIC — `InterviewController::start()` re-offers a competency that ended
 *     in `error`, bounded to one re-offer by `InterviewSession::MAX_ERROR_ATTEMPTS`.
 *   - OPERATOR — `RecoverFailedParticipant`, deliberately UNBOUNDED (D4). An
 *     operator acting on a known incident is not the failure mode the ceiling
 *     exists to contain.
 *
 * The two differ on exactly two axes: what triggers them, and whether a bound
 * applies. Everything about the reset itself is identical, and lives here.
 *
 * WHY `error_count` IS NOT TOUCHED — this is the whole bound.
 * The counter records attempts spent. Clearing it here would let the same
 * competency be re-offered forever: reset, fail, reset, fail. A counter that its
 * own reset clears is not a bound. `markSessionError()` is its sole writer.
 *
 * WHY THE UTTERANCES GO.
 * Ratified in `openspec/specs/participant-sso/spec.md` for the operator path and
 * extended to the automatic one: the transcript of a competency must hold ONE
 * attempt. Keeping the first attempt's turns would leave BARS scoring a
 * conversation that never happened — and `replaceUtterances()` cannot be relied
 * on to clear them later, because it returns early on an empty provider
 * transcript, which is exactly what a still-degraded provider returns.
 * Deleting on reset, not on write, closes that hole by timing.
 */
final class ResetSessionForRetry
{
    /**
     * Reset one errored session to `pending` and discard its transcript.
     *
     * @return int the number of utterances discarded, for the caller's report
     */
    public function __invoke(InterviewSession $session): int
    {
        $utterancesDiscarded = $session->utterances()->count();
        $session->utterances()->delete();

        $session->status = 'pending';
        $session->provider_session_ref = null;
        $session->ended_reason = null;
        $session->ended_at = null;
        // error_count is deliberately absent — see the class docblock.
        //
        // (interview-session-started-at, D6) `started_at` and every
        // `interview_session_live_periods` row are ALSO deliberately absent
        // here — the same judgement applied to a different column. The
        // minutes already spent in the failed attempt were genuinely
        // billed; only the WORDS must not survive into a re-scored
        // transcript. The next `/start` opens a SECOND stretch through
        // `handleIssuePending` (`started_at ??= now()` is a no-op for this
        // row), and that stretch is summed alongside the first.
        $session->save();

        return $utterancesDiscarded;
    }
}
