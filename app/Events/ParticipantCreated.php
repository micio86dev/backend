<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ParticipantCreated — fired when the SSO exchange creates a NEW participant
 * (C10 — Webhooks Integration, design.md D5).
 *
 * Scalar-only payload — never an Eloquent model (mirrors EvaluationFailed's
 * `participantId`-only shape). The listener re-loads with `withoutGlobalScopes()`
 * and derives the org itself.
 *
 * "Created" is inferred at the emission site from the PRE-FLIGHT read
 * (`SsoExchangeController.php:119-121`, `$existingStatus === null`) — BEFORE the
 * upsert runs, not from any state on this event.
 *
 * REQ: SSO progress seam (C10 D5)
 */
final class ParticipantCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $participantId,
        public readonly int $projectId,
    ) {}
}
