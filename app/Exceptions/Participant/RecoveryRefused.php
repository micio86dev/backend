<?php

declare(strict_types=1);

namespace App\Exceptions\Participant;

use RuntimeException;

/**
 * Thrown by `App\Actions\Participant\RecoverFailedParticipant` when the
 * recovery is refused (participant-error-recovery, design D5/D6).
 *
 * Carries a closed-set `reason` — never an HTTP status. The controller maps
 * it onto the endpoint's own 409 response shape.
 *
 * REQ: Recovery Refusal Guards
 *      (openspec/changes/participant-error-recovery/specs/participant-sso/spec.md)
 */
final class RecoveryRefused extends RuntimeException
{
    public function __construct(
        public readonly RecoveryRefusalReason $reason,
    ) {
        parent::__construct($reason->value);
    }
}
