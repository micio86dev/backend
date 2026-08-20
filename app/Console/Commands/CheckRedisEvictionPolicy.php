<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\RedisEvictionPolicyProbe;
use Illuminate\Console\Command;

/**
 * `php artisan beai:check-redis-eviction` (backoffice-session-refresh-hardening
 * D3, corrects the proposal's "not allkeys-*" to the strict requirement).
 *
 * Every refresh-token key (App\Support\Auth\RefreshTokenStore) carries a
 * TTL, so every `volatile-*` eviction policy is EXACTLY as fatal as
 * `allkeys-*` — both would silently drop live refresh families under memory
 * pressure, logging every operator out with no error on their side. The
 * only safe value is `noeviction`, under which a full Redis instance fails
 * writes loudly instead.
 *
 * Run as a deploy step and in CI. See docs/deploy.md.
 */
final class CheckRedisEvictionPolicy extends Command
{
    protected $signature = 'beai:check-redis-eviction';

    protected $description = 'Fail loudly unless the Redis maxmemory-policy is noeviction — every refresh-token key carries a TTL, so volatile-* is exactly as fatal as allkeys-*';

    public function handle(RedisEvictionPolicyProbe $probe): int
    {
        $policy = $probe->resolve();

        if ($policy === 'noeviction') {
            $this->info('maxmemory-policy: noeviction (OK).');

            return self::SUCCESS;
        }

        $this->error(
            "maxmemory-policy is '{$policy}', but MUST be 'noeviction'. Every ".
            'refresh-token key in this design carries a TTL, so every volatile-* '.
            'policy evicts active sessions exactly as readily as allkeys-* — '.
            'either would silently log every operator out under memory pressure. '.
            'Set maxmemory-policy=noeviction on the Redis instance backing '.
            'CACHE_STORE. See docs/deploy.md.'
        );

        return self::FAILURE;
    }
}
