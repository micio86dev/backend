<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * EvaluationCompleted — emitted when a scoring Evaluation reaches a terminal state.
 *
 * Fired for BOTH completed and pending Evaluations (both resolve the participant
 * to 'completato'; pending is an Evaluation sub-state, not a participant state).
 *
 * Carries the evaluationId for C10 webhook fanout. The Evaluation payload (status,
 * scores, reliability values) is loaded by the C10 listener from the evaluationId.
 *
 * Race guard: also emitted when the participant was concurrently moved to 'errore'
 * (failed() race condition per D9 FIX-7) — the Evaluation is still finalized and
 * the event fires unconditionally.
 *
 * REQ: D9 EvaluationCompleted event (C9 PR3)
 */
class EvaluationCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $evaluationId,
    ) {}
}
