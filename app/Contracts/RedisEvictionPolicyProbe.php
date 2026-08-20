<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Resolves the active Redis `maxmemory-policy` (backoffice-session-refresh-hardening
 * D3). Bound to App\Support\Auth\RedisConfigEvictionPolicyProbe in
 * AppServiceProvider — an interface (mirroring the LLMProvider pattern) so
 * App\Http\Controllers\QueueHealthController stays unit-testable with a
 * simple fake, without a real Redis instance or mocking a final class.
 */
interface RedisEvictionPolicyProbe
{
    /**
     * @return string The policy value (e.g. 'noeviction', 'allkeys-lru'), or
     *                'unknown' if it could not be read (non-Redis store, or
     *                the provider disables CONFIG).
     */
    public function resolve(): string;
}
