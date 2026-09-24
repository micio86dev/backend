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

use App\Enums\ApiKeyMode;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApiKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
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

    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
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

    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
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

// ─── Review follow-up (finding 1): a beai_test_ key must never authenticate
// on the internal `api-m2m` surface — only on the public `/v1` surface
// (`App\Http\Middleware\PublicApi\AuthenticatePublicApi`). Before the fix,
// ApiKeyResolver::resolve() had no notion of which surface was calling it, so
// a test key issued for local/browser experimentation had full access to
// live tenant data through /api/m2m/*. ─────────────────────────────────────

test('a test-mode key → 401 on the internal auth:api-m2m guard', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Test);

    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'mode' => 'test',
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/test-m2m-guard')
        ->assertUnauthorized();
});

test('a live-mode key still → 200 on the internal auth:api-m2m guard', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);

    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'mode' => 'live',
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/test-m2m-guard')
        ->assertOk();
});

// ─── Review follow-up (finding 2): the legacy key_hash fallback must not run
// on every prefix miss — it is skipped entirely for a beai_test_ key (no
// pre-migration row can ever be a test key), and for a beai_live_ key it only
// runs when at least one legacy row (key_prefix IS NULL) exists, per a cached
// flag. ──────────────────────────────────────────────────────────────────────

test('a well-formed but invalid live key issues exactly one query when no legacy rows exist', function (): void {
    // Seed the "no legacy rows" cache flag OUTSIDE the measured window: a
    // cold cache would itself issue the legacy-rows-exist query, which is
    // not what this test is pinning (the resolver's own cache population is
    // covered by the assertion below staying at exactly one query).
    Cache::put('api_clients.legacy_rows_exist', false, 300);

    $wellFormedInvalidKey = ApiKeyGenerator::generate(ApiKeyMode::Live);

    DB::enableQueryLog();

    $this->withHeaders(['Authorization' => 'Bearer '.$wellFormedInvalidKey])
        ->getJson('/api/test-m2m-guard')
        ->assertUnauthorized();

    $queryLog = DB::getQueryLog();
    DB::disableQueryLog();

    $apiClientQueries = array_filter($queryLog, fn (array $entry): bool => str_contains($entry['query'], 'api_clients'));

    expect($apiClientQueries)->toHaveCount(1);
});

test('a legacy pre-migration row still authenticates once legacy rows exist', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->withRawKey($rawKey)->preMigrationRow()->create([
        'organization_id' => $org->id,
    ]);

    Cache::forget('api_clients.legacy_rows_exist');

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/test-m2m-guard')
        ->assertOk();
});
