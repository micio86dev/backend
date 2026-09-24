<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

use Carbon\CarbonImmutable;

/**
 * The result of `App\Support\PublicApi\SessionTokenMinter::mint()`
 * (public-api step 5, SPEC.md §3.5).
 */
final class MintedSessionToken
{
    public function __construct(
        public readonly string $token,
        public readonly string $jti,
        public readonly CarbonImmutable $expiresAt,
    ) {}
}
