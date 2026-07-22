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

    public function __construct(
        public readonly int $participantId,
    ) {}
}
