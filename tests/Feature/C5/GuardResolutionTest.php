<?php

declare(strict_types=1);

/**
 * Guard resolution feature tests (C5 — M2M API Authentication).
 *
 * Asserts:
 * - Valid active key → guard returns ApiClient (200 on protected route)
 * - Unknown key → null → 401
 * - Inactive key → null → 401
 * - Expired key → null → 401
 * - Missing Authorization header → null → 401
 * - JWT string on auth:api-m2m route → 401 (guard non-interchangeability)
 *
 * REQ-3, REQ-10
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApiKeyGenerator;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    // Register a minimal test route protected by api-m2m guard.
    // Must be under /api/ so the JSON exception handler recognises it.
    Route::middleware('auth:api-m2m')
        ->prefix('api')
        ->get('/test-m2m-guard', fn () => response()->json(['ok' => true]));
});

test('valid active key → 200 on auth:api-m2m route', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash' => ApiKeyGenerator::hash($rawKey),
        'is_active' => true,
        'expires_at' => null,
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/test-m2m-guard');

    $response->assertOk();
});

test('unknown key → 401', function (): void {
    $unknownKey = ApiKeyGenerator::generate();

    $this->withHeaders(['Authorization' => 'Bearer '.$unknownKey])
        ->getJson('/api/test-m2m-guard')
        ->assertUnauthorized();
});

test('inactive key → 401', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash' => ApiKeyGenerator::hash($rawKey),
        'is_active' => false,
        'expires_at' => null,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/test-m2m-guard')
        ->assertUnauthorized();
});

test('expired key → 401', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash' => ApiKeyGenerator::hash($rawKey),
        'is_active' => true,
        'expires_at' => now()->subHour(),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/test-m2m-guard')
        ->assertUnauthorized();
});

test('missing Authorization header → 401', function (): void {
    $this->getJson('/api/test-m2m-guard')
        ->assertUnauthorized();
});

test('JWT string on auth:api-m2m route → 401 (guard non-interchangeability)', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $jwt = auth('api')->login($user);

    // A valid human JWT must NOT authenticate on the M2M guard
    $this->withHeaders(['Authorization' => 'Bearer '.$jwt])
        ->getJson('/api/test-m2m-guard')
        ->assertUnauthorized();
});
