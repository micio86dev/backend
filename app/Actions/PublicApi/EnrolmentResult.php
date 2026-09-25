<?php

declare(strict_types=1);

namespace App\Actions\PublicApi;

use App\Models\Participant;
use App\Support\PublicApi\MintedSessionToken;

/**
 * Successful outcome of `App\Actions\PublicApi\EnrolCandidate::handle()`
 * (public-api step 5) — the created enrolment and its first minted session
 * token, together, since `POST /v1/interviews` returns both in one response
 * (`CreateInterviewResponse`).
 */
final class EnrolmentResult
{
    public function __construct(
        public readonly Participant $participant,
        public readonly MintedSessionToken $sessionToken,
    ) {}
}
