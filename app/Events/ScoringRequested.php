<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ScoringRequested event (C9 — Scoring Engine).
 *
 * Emitted by FinalizeInterview (via TODO(C9) hook, wired in PR3) when an
 * interview session is complete and scoring should begin.
 *
 * Listened to by DispatchScoringJob (PR3) which dispatches ScoreEvaluationJob.
 *
 * REQ: Scoring trigger event (C9 D2)
 */
final class ScoringRequested
{
    use Dispatchable, SerializesModels;

    /**
     * `organizationId` (pre-commit gate, public-api step 9, findings 1/2 and
     * their round-2 correction): `Participant` carries no global scope, so
     * `DispatchScoringJob`'s own `find($event->participantId)` had no org
     * filter at all. `FinalizeInterview` now carries its OWN
     * `$organizationId` constructor argument — sourced from
     * `SettleParticipantCompletion`'s independently-resolved `$orgId` (read
     * from the `Project` row, never from an unscoped `Participant` read) —
     * and threads that same value here, so this event's `organizationId`
     * traces back to a genuinely independent source, not a value read off
     * the very row the filter exists to guard.
     */
    public function __construct(
        public readonly int $participantId,
        public readonly int $organizationId,
    ) {}
}
