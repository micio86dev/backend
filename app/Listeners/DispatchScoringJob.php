<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ApiKeyMode;
use App\Events\ScoringRequested;
use App\Jobs\ScoreEvaluationJob;
use App\Models\Participant;

/**
 * DispatchScoringJob — listens to ScoringRequested and dispatches ScoreEvaluationJob.
 *
 * This listener is the bridge between the C7a FinalizeInterview hook and the C9
 * async scoring pipeline. Registered in EventServiceProvider.
 *
 * Dispatch is via ::dispatch() (queue-backed via ShouldQueue on the job).
 * The afterCommit() guarantee is provided by the FinalizeInterview caller — the
 * ScoringRequested event is fired inside a committed HTTP response path, so no
 * additional afterCommit wrapping is needed here.
 *
 * SPEC.md §3.7 "Test mode" (public-api step 9): `ScoringRequested` also fires
 * for a `beai_test_…` participant — `SettleParticipantCompletion::
 * settleIfFinished()`'s `in_corso → in_valutazione` CAS win is reused
 * unconditionally by `App\Jobs\PublicApi\RunMockInterviewJob`, exactly the same
 * as the real `/end` path. But test-mode data is "never billed" (spec, verbatim)
 * and `RunMockInterviewJob` fabricates the Evaluation/CompetencyResult/
 * IndicatorScore rows itself, synchronously — dispatching the REAL, paid,
 * non-deterministic `ScoreEvaluationJob` for that same participant would
 * double-score it and violate that guarantee. `Participant` carries no global
 * scope (plain `Model`, per `SettleParticipantCompletion`'s own comment), so
 * this read is filtered by `$event->organizationId` — a genuinely
 * independent value `FinalizeInterview` sourced from
 * `SettleParticipantCompletion`'s own `Project`-derived `$orgId`, never
 * re-derived from this same unscoped `Participant` row (pre-commit gate,
 * public-api step 9, round 2 finding 1 — the FIRST version of this fix
 * threaded a value that traced back to an unfiltered read one call frame
 * up, which made the filter here circular). A participant that no longer
 * resolves is left to `ScoreEvaluationJob`'s own existing not-found handling
 * — never treated as test-mode here.
 *
 * REQ: D2 DispatchScoringJob listener (C9 PR3 Task 21.1); T-TEST-002 (public-api step 9)
 */
class DispatchScoringJob
{
    /**
     * Handle the ScoringRequested event.
     */
    public function handle(ScoringRequested $event): void
    {
        // org-filtered (pre-commit gate, public-api step 9, finding 2):
        // Participant carries no global scope, so an unfiltered find() had
        // no org guard at all — $event->organizationId is threaded through
        // by FinalizeInterview from its own already-loaded participant, the
        // same discipline SendEvaluationWebhook already applies at its own
        // Participant reads.
        $participant = Participant::where('organization_id', $event->organizationId)->find($event->participantId);

        if ($participant?->mode === ApiKeyMode::Test) {
            return;
        }

        ScoreEvaluationJob::dispatch($event->participantId);
    }
}
