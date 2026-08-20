<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * A freshly issued (or rotated) refresh-token generation (D2/D4).
 *
 * Immutable value object: the wire format `{family_id}.{secret}` is exactly
 * what App\Support\Auth\RefreshTokenCookie::build() places in the cookie —
 * the secret itself is NEVER logged or returned in any JSON response.
 */
final class RefreshTokenIssue
{
    public function __construct(
        public readonly string $familyId,
        public readonly string $secret,
        public readonly int $absoluteExpiresAt,
    ) {}

    public function cookieValue(): string
    {
        return "{$this->familyId}.{$this->secret}";
    }
}
