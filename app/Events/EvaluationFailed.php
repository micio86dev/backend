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
 * `$organizationId` (pre-commit gate, round 5, finding 2): threaded through from
 * `ScoreEvaluationJob`'s OWN already-derived/validated org id at every dispatch site
 * where one is known — see `ScoreEvaluationJob::failed()`/`endParticipantUnresolvable()`.
 * This gives `App\Listeners\SendEvaluationWebhook::handleFailed()` an organization id
 * that does NOT depend on that listener's own (unscoped) `Participant` read, closing a
 * circular check the previous round's fix left open. `null` ONLY on the genuine
 * "cannot derive organization context" branch of `failed()`, where no trustworthy org
 * is known even to the job itself — D9's "ALWAYS emit" still outranks that gap.
 *
 * REQ: EvaluationFailed event (C9 D9)
 */
final class EvaluationFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $participantId,
        public readonly ?int $organizationId = null,
    ) {}
}
