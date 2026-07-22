<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * EvaluationFailed event (C9 — Scoring Engine).
 *
 * Emitted by ScoreEvaluationJob::failed() when the job exhausts all queue
 * retries. Notifies C10 (webhooks) of a catastrophic scoring failure.
 *
 * Emission contract (D9 CC5):
 * - ALWAYS emitted from failed(), regardless of whether the participant status
 *   transition was performed or skipped (guard: only transition if in_valutazione).
 * - If participant is already 'errore', skip the transition but still emit.
 *
 * REQ: EvaluationFailed event (C9 D9)
 */
final class EvaluationFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $participantId,
    ) {}
}
