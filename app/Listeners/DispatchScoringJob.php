<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ScoringRequested;
use App\Jobs\ScoreEvaluationJob;

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
 * REQ: D2 DispatchScoringJob listener (C9 PR3 Task 21.1)
 */
class DispatchScoringJob
{
    /**
     * Handle the ScoringRequested event.
     */
    public function handle(ScoringRequested $event): void
    {
        ScoreEvaluationJob::dispatch($event->participantId);
    }
}
