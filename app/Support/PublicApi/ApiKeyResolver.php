<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

use App\Models\ApiClient;
use App\Services\ApiKeyGenerator;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves a raw bearer key to its `ApiClient`, for BOTH the C5 `api-m2m`
 * guard (`AppServiceProvider::boot()`) and the public-api step 2
 * `App\Http\Middleware\PublicApi\AuthenticatePublicApi` middleware.
 *
 * Extracted from the `api-m2m` guard closure (public-api step 2) rather than
 * duplicated: the two surfaces used to implement the exact same
 * hash-lookup/denylist/throttled-touch sequence independently, and step 2's
 * new PREFIX-aware lookup (below) is exactly the kind of security-relevant
 * logic that must never exist in two copies that can drift.
 *
 * Resolution order (SPEC.md §8 Q1):
 *   1. If the raw key carries a recognised `beai_live_`/`beai_test_` marker
 *      (`ApiKeyGenerator::modeOf() !== null`), look up EVERY active row
 *      sharing its `key_prefix` and decide with `hash_equals()` against each
 *      candidate's `key_hash` — never a `where('key_hash', ...)` on the raw
 *      key itself. Multiple rows can share a prefix (the visible part is
 *      only 8 chars of the random suffix); `hash_equals()` is what makes the
 *      DECISION timing-safe once the candidate set is narrowed.
 *   2. Fall back to the legacy `where('key_hash', $hash)` lookup — required
 *      for every row created before this migration (`key_prefix` is null,
 *      by construction unrecoverable) and harmless as a fallback for a
 *      malformed/unrecognised key (step 1 above is simply skipped for it).
 *
 * The raw key itself never reaches a query binding either way — only its
 * SHA-256 digest and, for the prefix branch, its own first-8-chars marker
 * (T-AUTH-009).
 */
final class ApiKeyResolver
{
    public static function resolve(string $rawKey): ?ApiClient
    {
        if ($rawKey === '') {
            return null;
        }

        $client = self::lookup($rawKey);

        if ($client === null) {
            return null;
        }

        // Redis denylist check: client_revoked:{id}
        // Fail-safe: on Redis outage, fall back to a FRESH DB active() re-query.
        // NEVER fail-open — is_active is the durable authoritative revocation flag.
        try {
            if (Cache::has('client_revoked:'.$client->id)) {
                return null;
            }
        } catch (\Throwable) {
            $client = self::lookup($rawKey);

            if ($client === null) {
                return null;
            }
        }

        // Throttled last_used_at update — best-effort, non-fatal.
        // Write only if null or older than 5 minutes to avoid per-request writes.
        try {
            if ($client->last_used_at === null || $client->last_used_at->lt(now()->subMinutes(5))) {
                $client->updateQuietly(['last_used_at' => now()]);
            }
        } catch (\Throwable) {
            // Non-fatal — telemetry write failure must never reject an authenticated request.
        }

        return $client;
    }

    private static function lookup(string $rawKey): ?ApiClient
    {
        $hash = ApiKeyGenerator::hash($rawKey);

        if (ApiKeyGenerator::modeOf($rawKey) !== null) {
            $prefix = ApiKeyGenerator::prefixOf($rawKey);

            /** @var iterable<int, ApiClient> $candidates */
            $candidates = ApiClient::active()->where('key_prefix', $prefix)->get();

            foreach ($candidates as $candidate) {
                if (hash_equals($candidate->key_hash, $hash)) {
                    return $candidate;
                }
            }
        }

        // Legacy fallback — pre-migration rows (null key_prefix) and any key
        // whose marker did not parse above.
        return ApiClient::active()->where('key_hash', $hash)->first();
    }
}
