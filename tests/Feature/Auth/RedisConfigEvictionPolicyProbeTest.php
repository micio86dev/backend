<?php

declare(strict_types=1);

/**
 * The probe reads `CONFIG GET maxmemory-policy` and must never throw: any
 * failure reports 'unknown' so the health surface treats it like a wrong policy
 * (fail loud) instead of crashing the health endpoint.
 */

use App\Support\Auth\RedisConfigEvictionPolicyProbe;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Redis\Connection;
use Illuminate\Support\Facades\Cache;

function probeWithRedisReply(mixed $reply): string
{
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('command')->with('config', ['GET', 'maxmemory-policy'])->andReturn($reply);
    $store = Mockery::mock(RedisStore::class);
    $store->shouldReceive('connection')->andReturn($connection);
    $repository = Mockery::mock(Repository::class);
    $repository->shouldReceive('getStore')->andReturn($store);
    Cache::shouldReceive('store')->with('redis')->andReturn($repository);

    return (new RedisConfigEvictionPolicyProbe)->resolve();
}

test('reads the policy from the associative reply phpredis returns', function (): void {
    expect(probeWithRedisReply(['maxmemory-policy' => 'noeviction']))->toBe('noeviction');
});

test('reads the policy from the flat pair predis returns', function (): void {
    expect(probeWithRedisReply(['maxmemory-policy', 'allkeys-lru']))->toBe('allkeys-lru');
});

test('an unrecognisable reply is unknown, not a guess', function (): void {
    expect(probeWithRedisReply('OK'))->toBe('unknown')
        ->and(probeWithRedisReply([]))->toBe('unknown');
});

test('a managed Redis that disables CONFIG reports unknown instead of throwing', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('command')->andThrow(new RuntimeException('ERR unknown command `CONFIG`'));
    $store = Mockery::mock(RedisStore::class);
    $store->shouldReceive('connection')->andReturn($connection);
    $repository = Mockery::mock(Repository::class);
    $repository->shouldReceive('getStore')->andReturn($store);
    Cache::shouldReceive('store')->with('redis')->andReturn($repository);

    expect((new RedisConfigEvictionPolicyProbe)->resolve())->toBe('unknown');
});

test('a cache store that is not Redis reports unknown', function (): void {
    $repository = Mockery::mock(Repository::class);
    $repository->shouldReceive('getStore')->andReturn(new ArrayStore);
    Cache::shouldReceive('store')->with('redis')->andReturn($repository);

    expect((new RedisConfigEvictionPolicyProbe)->resolve())->toBe('unknown');
});
