<?php

declare(strict_types=1);

/**
 * Redis denylist fail-safe tests (C5 — M2M API Authentication).
 *
 * Asserts that when Redis is unavailable:
 * - A key whose DB record is is_active=false is still rejected (401)
 * - A valid non-revoked key still authenticates (200) — guard does NOT fail-open
 * - Guard NEVER fails-open on Redis outage
 *
 * REQ-3 / design §Redis denylist / fail-safe
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Services\ApiKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware('auth:api-m2m')
        ->prefix('api')
        ->get('/test-m2m-failsafe', fn () => response()->json(['ok' => true]));
});

test('Redis down + revoked (is_active=false) key → 401 via DB re-query', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash' => ApiKeyGenerator::hash($rawKey),
        'is_active' => false,
    ]);

    // Simulate Redis outage — any cache operation throws
    Cache::shouldReceive('has')->andThrow(new RuntimeException('Redis connection failed'));
    Cache::shouldReceive('get')->andThrow(new RuntimeException('Redis connection failed'));

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/test-m2m-failsafe')
        ->assertUnauthorized();
});

test('Redis down + valid non-revoked key → 200 (DB fallback, no fail-open)', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash' => ApiKeyGenerator::hash($rawKey),
        'is_active' => true,
        'expires_at' => null,
    ]);

    // Simulate Redis outage
    Cache::shouldReceive('has')->andThrow(new RuntimeException('Redis connection failed'));
    Cache::shouldReceive('get')->andThrow(new RuntimeException('Redis connection failed'));

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/test-m2m-failsafe')
        ->assertOk();
});

test('guard never fails-open — Redis down + expired key → 401', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash' => ApiKeyGenerator::hash($rawKey),
        'is_active' => true,
        'expires_at' => now()->subMinutes(5),
    ]);

    Cache::shouldReceive('has')->andThrow(new RuntimeException('Redis connection failed'));
    Cache::shouldReceive('get')->andThrow(new RuntimeException('Redis connection failed'));

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/test-m2m-failsafe')
        ->assertUnauthorized();
});
