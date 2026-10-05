<?php

declare(strict_types=1);

namespace App\Exceptions\Participant;

use RuntimeException;

/**
 * Thrown by `App\Actions\Participant\AuthorizeEvaluationRetry` when the retry
 * is refused (scoring-retry-rt-b, design D4).
 *
 * Carries a closed-set `reason` and never an HTTP status: each controller maps
 * it onto its own 409 response shape, as `RecoveryRefused` does for the
 * recovery endpoint.
 */
final class EvaluationRetryRefused extends RuntimeException
{
    public function __construct(
        public readonly EvaluationRetryRefusalReason $reason,
    ) {
        parent::__construct($reason->value);
    }
}
