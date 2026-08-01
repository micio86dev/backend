<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * CompetencySessionEnded — fired when a competency's `/end` write COMMITS
 * (C10 — Webhooks Integration, design.md D5).
 *
 * Scalar-only payload — never an Eloquent model. Emitted AFTER
 * `InterviewController::end()`'s `DB::transaction()` closure returns (i.e. only
 * after a successful COMMIT), mirroring the existing
 * `FinalizeInterview::dispatch($pid)->afterCommit()` precedent at
 * `InterviewController.php:264`. `abort(404)`/`abort(409)` inside the closure throw
 * past the post-closure emission entirely, so nothing is emitted on a rolled-back
 * write.
 *
 * REQ: `/end` progress seam (C10 D5)
 */
final class CompetencySessionEnded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $participantId,
        public readonly int $projectId,
        public readonly string $competencyCode,
    ) {}
}
