<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ApiKeyMode;
use InvalidArgumentException;
use Random\RandomException;

/**
 * Generates and hashes opaque M2M / public-API keys (C5, public-api step 2).
 *
 * Format: beai_{mode}_ + bin2hex(random_bytes(48))
 *   - prefix:  'beai_live_' / 'beai_test_' (10 chars) — `App\Enums\ApiKeyMode::marker()`
 *   - suffix:  96 hex chars = 384-bit entropy
 *
 * SPEC.md §3.1 documents `beai_live_<32 random url-safe chars>` as the key
 * FORMAT — read as a MINIMUM (SPEC.md §8 Q1): this generator already exceeds
 * it (384 bits vs. the minimum's ~190) and step 2 does not narrow it.
 *
 * Only SHA-256 of the raw key is persisted. The raw key is returned once in
 * the 201 response and must never be logged, stored, or serialized elsewhere.
 *
 * REQ-2 / design §Key generation
 */
final class ApiKeyGenerator
{
    /**
     * How many characters of the random part `prefixOf()` keeps visible.
     */
    private const VISIBLE_RANDOM_CHARS = 8;

    /**
     * Generate a new raw API key for the given mode.
     *
     * @param  'live'|'test'  $mode
     *
     * @throws RandomException if the CSPRNG fails
     */
    public static function generate(string $mode = 'live'): string
    {
        return self::markerFor($mode).bin2hex(random_bytes(48));
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

    /**
     * The visible identifier stored in `api_clients.key_prefix`: the
     * `beai_<mode>_` marker plus the first 8 chars of the random part.
     *
     * For identification only (e.g. "which key is this, in the backoffice
     * list") — never used to authenticate a request by itself.
     *
     * Review follow-up (finding 4): a malformed key has no marker to compute
     * a prefix from, so this THROWS rather than silently defaulting to the
     * live marker — the previous ternary treated "not test" as "must be
     * live", which is wrong for a key that is neither. Callers that only
     * want to test recognition should call `ApiKeyMode::fromMarker()` /
     * `modeOf()` first, as `App\Support\PublicApi\ApiKeyResolver` already does.
     *
     * @throws InvalidArgumentException when `$rawKey` carries no recognised
     *                                  `beai_live_`/`beai_test_` marker.
     */
    public static function prefixOf(string $rawKey): string
    {
        $mode = ApiKeyMode::fromMarker($rawKey);

        if ($mode === null) {
            throw new InvalidArgumentException('Cannot compute a key_prefix for a key with no recognised beai_live_/beai_test_ marker.');
        }

        $marker = $mode->marker();
        $random = substr($rawKey, strlen($marker));

        return $marker.substr($random, 0, self::VISIBLE_RANDOM_CHARS);
    }

    /**
     * The mode a raw key encodes, or null when the key does not carry a
     * recognised `beai_live_`/`beai_test_` marker (malformed / unknown key —
     * `App\Support\PublicApi\ApiKeyResolver` falls back to a legacy hash-only
     * lookup in that case, never a prefix lookup against a prefix that was
     * never computed from anything real).
     *
     * @return 'live'|'test'|null
     */
    public static function modeOf(string $rawKey): ?string
    {
        return ApiKeyMode::fromMarker($rawKey)?->value;
    }

    /**
     * `$mode` is plain `string`, deliberately UNDOCUMENTED as `'live'|'test'`
     * here even though `generate()` above documents its own `$mode` that way:
     * `generate()`'s own type is the (unenforced) documented contract, not a
     * static guarantee — a caller can still pass any string at runtime, which
     * is exactly what the `tryFrom() ?? Live` fallback below defends against.
     * Narrowing this parameter's docblock to `'live'|'test'` made PHPStan
     * infer `tryFrom()` as never-null and flag the fallback as dead code,
     * which would have been true only if the narrower type were an actual
     * static guarantee.
     */
    private static function markerFor(string $mode): string
    {
        return (ApiKeyMode::tryFrom($mode) ?? ApiKeyMode::Live)->marker();
    }
}
