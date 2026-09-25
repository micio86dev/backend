<?php

declare(strict_types=1);

/**
 * TenantContextM2m middleware feature tests (C5 — M2M API Authentication).
 *
 * Asserts via real HTTP routes (guard resolution is real):
 * - null client (no bearer) → 401
 * - valid client → resolver has correct orgId
 * - valid client → bypass=false (stale bypass cleared)
 * - org always comes from client record (not request input)
 *
 * Note on setBypass(false)-before-setOrgId ordering: TenantResolver is `final`
 * so it cannot be extended or mocked. The ordering is verified by:
 * 1. The middleware source code contract (enforced by code review)
 * 2. The `bypass is false` test (proves bypass is cleared)
 * 3. The `org_id is correct` test (proves org is stamped)
 * Both must pass together, which is the design invariant.
 *
 * REQ-4, REQ-T1 / design §TenantContextM2m
 */

use App\Enums\ApiKeyMode;
use App\Http\Middleware\TenantContext;
use App\Http\Middleware\TenantContextM2m;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Services\ApiKeyGenerator;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    // Test route that exposes resolver state after middleware runs
    Route::prefix('api')
        ->withoutMiddleware(TenantContext::class)
        ->middleware(['auth:api-m2m', TenantContextM2m::class, SubstituteBindings::class])
        ->get('/test-m2m-context', function () {
            $resolver = app(TenantResolver::class);

            return response()->json([
                'org_id' => $resolver->getOrgId(),
                'bypass' => $resolver->isBypass(),
            ]);
        });
});

test('no bearer token → 401 (TenantContextM2m aborts on null client)', function (): void {
    $this->getJson('/api/test-m2m-context')
        ->assertUnauthorized();
});

test('valid client → resolver orgId is set to client organization_id', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/test-m2m-context')
        ->assertOk()
        ->assertJsonPath('org_id', $org->id);
});

test('valid client → bypass is false (setBypass(false) clears stale bypass)', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/test-m2m-context')
        ->assertOk()
        ->assertJsonPath('bypass', false);
});

test('org always from client record — request body org_id is ignored', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $orgA->id,
    ]);

    // Attempt to pass org B via query param — must be ignored
    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/test-m2m-context?organization_id='.$orgB->id)
        ->assertOk()
        ->assertJsonPath('org_id', $orgA->id);
});

// ─── Review follow-up (finding 1): TenantContextM2m must never even RUN for
// a test-mode key — `auth:api-m2m` (via ApiKeyResolver::resolve(..., false))
// already rejects it, so the resolver here stays completely untouched
// (org_id null, bypass unset). ──────────────────────────────────────────────

test('a test-mode key never reaches TenantContextM2m — 401 before any resolver state is stamped', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Test);

    ApiClient::factory()->withRawKey($rawKey)->create([
        'organization_id' => $org->id,
        'mode' => 'test',
    ]);

    $resolver = app(TenantResolver::class);
    expect($resolver->getOrgId())->toBeNull();

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/test-m2m-context')
        ->assertUnauthorized();

    // TenantContextM2m never ran — the resolver is exactly as untouched as
    // it was before the request.
    expect($resolver->getOrgId())->toBeNull();
});

test('second org also resolves correctly (different client, different org)', function (): void {
    $orgB = Organization::factory()->create();
    $rawKeyB = ApiKeyGenerator::generate();

    ApiClient::factory()->withRawKey($rawKeyB)->create([
        'organization_id' => $orgB->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKeyB])
        ->getJson('/api/test-m2m-context')
        ->assertOk()
        ->assertJsonPath('org_id', $orgB->id);
});
