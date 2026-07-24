<?php

declare(strict_types=1);

namespace App\Services;

use Random\RandomException;

/**
 * Generates and hashes opaque M2M API keys (C5).
 *
 * Format: beai_live_ + bin2hex(random_bytes(48))
 *   - prefix:  'beai_live_' (9 chars)
 *   - suffix:  96 hex chars = 384-bit entropy
 *
 * Only SHA-256 of the raw key is persisted. The raw key is returned once in
 * the 201 response and must never be logged, stored, or serialized elsewhere.
 *
 * REQ-2 / design §Key generation
 */
final class ApiKeyGenerator
{
    private const PREFIX = 'beai_live_';

    /**
     * Generate a new raw API key.
     *
     * @throws RandomException if the CSPRNG fails
     */
    public static function generate(): string
    {
        return self::PREFIX.bin2hex(random_bytes(48));
    }

    /**
     * Hash a raw API key for storage.
     *
     * Only the hash is ever persisted — never the raw key.
     */
    public static function hash(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }
}
