<?php

/**
 * RED→GREEN — 2.5: `beai:check-redis-eviction` (backoffice-session-refresh-hardening
 * D3, corrects the proposal's "not allkeys-*"). Every refresh-token key
 * carries a TTL, so every `volatile-*` policy is exactly as fatal as
 * `allkeys-*` — the ONLY safe value is `noeviction`.
 */

use App\Contracts\RedisEvictionPolicyProbe;

/**
 * Fakes the probe (LLMProvider container-binding pattern) rather than
 * mocking the final concrete RedisConfigEvictionPolicyProbe implementation.
 */
function fakeRedisEvictionPolicyProbe(string $policy): void
{
    app()->instance(RedisEvictionPolicyProbe::class, new class($policy) implements RedisEvictionPolicyProbe
    {
        public function __construct(private readonly string $policy) {}

        public function resolve(): string
        {
            return $this->policy;
        }
    });
}

test('exits 0 when the policy is noeviction', function (): void {
    fakeRedisEvictionPolicyProbe('noeviction');

    $this->artisan('beai:check-redis-eviction')->assertExitCode(0);
});

test('exits non-zero when the policy is allkeys-lru', function (): void {
    fakeRedisEvictionPolicyProbe('allkeys-lru');

    $this->artisan('beai:check-redis-eviction')->assertExitCode(1);
});

// D3's explicit correction: "not allkeys-*" is NOT strict enough — every
// volatile-* policy is equally fatal because every key here carries a TTL.
test('exits non-zero on EVERY volatile-* policy, not only allkeys-*', function (string $policy): void {
    fakeRedisEvictionPolicyProbe($policy);

    $this->artisan('beai:check-redis-eviction')->assertExitCode(1);
})->with([
    'volatile-lru',
    'volatile-lfu',
    'volatile-random',
    'volatile-ttl',
]);

test('exits non-zero and reports "unknown" when CONFIG GET cannot be read', function (): void {
    fakeRedisEvictionPolicyProbe('unknown');

    $this->artisan('beai:check-redis-eviction')
        ->expectsOutputToContain('unknown')
        ->assertExitCode(1);
});
