<?php

declare(strict_types=1);

/**
 * ApiClientController::destroy — Redis TTL and Redis-fail branches (C5).
 *
 * Covers the two branches flagged by sdd-verify as uncovered:
 *
 * W2a (line 139) — client with a FUTURE expires_at:
 *   The Redis TTL must EQUAL the remaining lifetime (expires_at – now in
 *   seconds, floored at 1). "Positive" is not the invariant — Carbon 3 returns
 *   a signed diff, so the wrong argument order also produces a positive TTL:
 *   the constant 1. The assertions here pin the exact second count.
 *
 * W2b (line 143) — Redis throws during destroy():
 *   The DB write (is_active=false) must survive a Redis outage.
 *   The endpoint must still return 204 — revocation is not blocked by Redis.
 *   The raw key must never appear in the exception or response.
 *
 * REQ-7, REQ-8 / design §Revocation write ordering
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

// ---------------------------------------------------------------------------
// Helper (mirrors the one in ApiClientDestroyTest.php, scoped to this file)
// ---------------------------------------------------------------------------

function destroyTtlAdminToken(Organization $org): string
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return (string) auth('api')->login($user);
}

// ---------------------------------------------------------------------------
// W2a — future expires_at → positive Redis TTL
// ---------------------------------------------------------------------------

test('W2 — destroy with future expires_at writes the REMAINING LIFETIME as the Redis TTL', function (): void {
    // Time is frozen so the expected TTL is an exact number, not a window.
    // Without this the assertion below would have to be a range, and a range is
    // how the original bug survived: 1 second satisfies "greater than 0".
    $this->freezeTime();

    $org = Organization::factory()->create();
    $token = destroyTtlAdminToken($org);

    // Create a client that expires in exactly 1 hour from now
    $expiresAt = now()->addHour();
    $client = ApiClient::factory()->create([
        'organization_id' => $org->id,
        'is_active' => true,
        'expires_at' => $expiresAt,
    ]);

    $capturedTtl = null;

    Cache::shouldReceive('put')
        ->once()
        ->andReturnUsing(function (string $key, $value, int $ttl) use (&$capturedTtl, $client) {
            $capturedTtl = $ttl;

            // Key must be the revocation key for this client — raw key never exposed
            expect($key)->toBe('client_revoked:'.$client->id);
            expect($value)->toBeTrue();

            return true;
        });

    $this->withToken($token)
        ->deleteJson('/api/m2m/clients/'.$client->id)
        ->assertNoContent();

    // The TTL must BE the remaining lifetime — one hour, to the second.
    // The value the Carbon-3 signed-diff bug produces is 1; this assertion is
    // the only shape that excludes it.
    expect($capturedTtl)->toBe(3600);

    // DB write must still have happened
    $client->refresh();
    expect($client->is_active)->toBeFalse();
});

test('W2 — destroy with expires_at in the past clamps the TTL to exactly 1 (max(1, …) guard)', function (): void {
    $this->freezeTime();

    $org = Organization::factory()->create();
    $token = destroyTtlAdminToken($org);

    // Edge case: expires_at is 1 second in the past when destroy() fires.
    // now()->diffInSeconds(past) → -1; max(1, …) must clamp it to 1.
    $client = ApiClient::factory()->create([
        'organization_id' => $org->id,
        'is_active' => true,
        'expires_at' => now()->subSeconds(1),
    ]);

    $capturedTtl = null;

    Cache::shouldReceive('put')
        ->once()
        ->andReturnUsing(function (string $key, $value, int $ttl) use (&$capturedTtl) {
            $capturedTtl = $ttl;

            return true;
        });

    $this->withToken($token)
        ->deleteJson('/api/m2m/clients/'.$client->id)
        ->assertNoContent();

    // max(1, …) ensures TTL is never 0 or negative — and, with time frozen,
    // the clamped value is exactly 1.
    expect($capturedTtl)->toBe(1);
});

test('W2 — destroy with null expires_at uses 1-year fallback TTL', function (): void {
    $org = Organization::factory()->create();
    $token = destroyTtlAdminToken($org);

    $client = ApiClient::factory()->create([
        'organization_id' => $org->id,
        'is_active' => true,
        'expires_at' => null,
    ]);

    $capturedTtl = null;

    Cache::shouldReceive('put')
        ->once()
        ->andReturnUsing(function (string $key, $value, int $ttl) use (&$capturedTtl) {
            $capturedTtl = $ttl;

            return true;
        });

    $this->withToken($token)
        ->deleteJson('/api/m2m/clients/'.$client->id)
        ->assertNoContent();

    expect($capturedTtl)->toBe(365 * 24 * 3600);
});

// ---------------------------------------------------------------------------
// W2b — Redis failure during destroy(): DB write survives, 204 returned
// ---------------------------------------------------------------------------

test('W2 — Redis failure during destroy(): DB revocation survives, returns 204', function (): void {
    $org = Organization::factory()->create();
    $token = destroyTtlAdminToken($org);

    $client = ApiClient::factory()->create([
        'organization_id' => $org->id,
        'is_active' => true,
    ]);

    // Simulate Redis outage — Cache::put throws
    Cache::shouldReceive('put')
        ->once()
        ->andThrow(new RuntimeException('Redis connection refused'));

    // Endpoint must still succeed — Redis failure is non-fatal
    $this->withToken($token)
        ->deleteJson('/api/m2m/clients/'.$client->id)
        ->assertNoContent();

    // DB revocation MUST have been committed — this is the authoritative flag
    $client->refresh();
    expect($client->is_active)->toBeFalse();
});

test('W2 — Redis failure response body does not expose raw key material', function (): void {
    $org = Organization::factory()->create();
    $token = destroyTtlAdminToken($org);

    $client = ApiClient::factory()->create([
        'organization_id' => $org->id,
        'is_active' => true,
    ]);

    Cache::shouldReceive('put')
        ->once()
        ->andThrow(new RuntimeException('connection refused'));

    $response = $this->withToken($token)
        ->deleteJson('/api/m2m/clients/'.$client->id);

    // 204 No Content — no body to leak key material through
    $response->assertNoContent();
    expect($response->getContent())->toBe('');
});
