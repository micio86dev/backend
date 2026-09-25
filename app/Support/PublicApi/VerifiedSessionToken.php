<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

use Carbon\CarbonImmutable;

/**
 * A cryptographically-verified (signature, issuer, audience) BEAI Public
 * API (`/v1`) session token's claims — the result of
 * `App\Support\PublicApi\SessionTokenMinter::parse()` (public-api step 5,
 * SPEC.md §3.5). `$expiresAt` is NOT checked by the minter itself; the
 * caller decides via `isExpired()`.
 */
final class VerifiedSessionToken
{
    public function __construct(
        public readonly string $subject,
        public readonly string $jti,
        public readonly string $organizationPublicId,
        public readonly string $mode,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    public function isExpired(): bool
    {
        return $this->expiresAt->isPast();
    }
}
