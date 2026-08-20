<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * Immutable result of App\Support\Auth\RefreshTokenStore::rotate() (D2/D6).
 * Named constructors mirror RefreshRotateStatus's cases 1:1.
 */
final class RefreshRotateResult
{
    private function __construct(
        public readonly RefreshRotateStatus $status,
        public readonly ?int $userId = null,
        public readonly ?RefreshTokenIssue $issue = null,
        public readonly ?string $familyId = null,
    ) {}

    public static function rotated(int $userId, RefreshTokenIssue $issue): self
    {
        return new self(RefreshRotateStatus::Rotated, $userId, $issue, $issue->familyId);
    }

    /**
     * D6: no rotation, no new cookie — $familyId is still needed by the
     * caller to stamp the SAME `fam` claim onto the freshly minted access
     * token (the family itself did not change on this path).
     */
    public static function concurrentDuplicate(int $userId, string $familyId): self
    {
        return new self(RefreshRotateStatus::ConcurrentDuplicate, $userId, familyId: $familyId);
    }

    public static function invalid(): self
    {
        return new self(RefreshRotateStatus::Invalid);
    }

    public static function revoked(): self
    {
        return new self(RefreshRotateStatus::Revoked);
    }

    public static function expired(): self
    {
        return new self(RefreshRotateStatus::Expired);
    }

    public static function reused(): self
    {
        return new self(RefreshRotateStatus::Reused);
    }
}
