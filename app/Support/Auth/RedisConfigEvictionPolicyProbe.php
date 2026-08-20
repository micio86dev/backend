<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Contracts\RedisEvictionPolicyProbe;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reads `CONFIG GET maxmemory-policy` off the `redis` cache connection
 * (backoffice-session-refresh-hardening D3). Mirrors the resolve-real-driver
 * shape of App\Support\Queue\ReservedJobAgeProbe — a thin, injectable probe
 * so App\Http\Controllers\QueueHealthController stays unit-testable without
 * a real Redis instance (bound to the App\Contracts\RedisEvictionPolicyProbe
 * interface in AppServiceProvider, mirroring the LLMProvider pattern).
 *
 * Managed Redis providers sometimes disable the CONFIG command entirely; on
 * ANY failure this reports 'unknown' rather than throwing, so a caller can
 * treat it identically to a wrong policy (fail loud, never fail silent).
 */
final class RedisConfigEvictionPolicyProbe implements RedisEvictionPolicyProbe
{
    public function resolve(): string
    {
        try {
            $store = Cache::store('redis')->getStore();

            if (! $store instanceof RedisStore) {
                return 'unknown';
            }

            /** @var mixed $result */
            $result = $store->connection()->command('config', ['GET', 'maxmemory-policy']);

            // phpredis returns an associative ['maxmemory-policy' => 'noeviction'];
            // predis returns a flat ['maxmemory-policy', 'noeviction'] pair.
            if (is_array($result) && array_key_exists('maxmemory-policy', $result)) {
                return (string) $result['maxmemory-policy'];
            }

            if (is_array($result) && count($result) >= 2) {
                return (string) array_values($result)[1];
            }

            return 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
