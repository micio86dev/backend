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
    ) {}

    public static function rotated(int $userId, RefreshTokenIssue $issue): self
    {
        return new self(RefreshRotateStatus::Rotated, $userId, $issue);
    }

    public static function concurrentDuplicate(int $userId): self
    {
        return new self(RefreshRotateStatus::ConcurrentDuplicate, $userId);
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
