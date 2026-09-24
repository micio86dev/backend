<?php

declare(strict_types=1);

namespace App\Exceptions\PublicApi;

use RuntimeException;

/**
 * Thrown by `App\Actions\PublicApi\EnrolCandidate::handle()` when the
 * enrolment is refused (public-api step 5). Mirrors
 * `App\Exceptions\Sso\EntryLinkRefused` exactly — carries a closed-set
 * `reason`, never an HTTP status; the controller builds the response.
 */
final class EnrolmentRefused extends RuntimeException
{
    public function __construct(
        public readonly EnrolmentRefusalReason $reason,
    ) {
        parent::__construct($reason->value);
    }
}
