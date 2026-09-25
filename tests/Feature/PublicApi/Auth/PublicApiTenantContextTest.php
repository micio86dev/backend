<?php

declare(strict_types=1);

/**
 * PublicApiTenantContext middleware tests (public-api step 2), exercised
 * DIRECTLY — no `AuthenticatePublicApi` in front — mirroring
 * `tests/Feature/C5/TenantContextM2mTest.php`'s own pattern for its M2M
 * twin: both fail-closed invariants documented on the class (null client,
 * invalid organization_id) are otherwise unreachable through the real `/v1`
 * route group, where `AuthenticatePublicApi` always runs first and always
 * either sets a client with a valid organization_id or short-circuits
 * itself.
 */

use App\Http\Middleware\PublicApi\PublicApiTenantContext;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Support\PublicApi\ApiMode;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware([PublicApiTenantContext::class, SubstituteBindings::class])
        ->prefix('api/v1')
        ->get('/_probe/tenant-context-only', function () {
            $resolver = app(TenantResolver::class);

            return response()->json([
                'org_id' => $resolver->getOrgId(),
                'bypass' => $resolver->isBypass(),
                'mode' => app(ApiMode::class)->get(),
            ]);
        });
});

test('no client set on the api-m2m guard → 401', function (): void {
    $this->getJson('/api/v1/_probe/tenant-context-only')
        ->assertUnauthorized();
});

test('a client with organization_id < 1 → 401 (fail-closed, misconfigured credential)', function (): void {
    $org = Organization::factory()->create();
    $client = ApiClient::factory()->make(['organization_id' => $org->id]);

    // Simulate a misconfigured/corrupt credential — organization_id resolves
    // to 0 rather than a real FK. `make()` (not `create()`): this row must
    // never actually be persisted, since organization_id is a NOT NULL FK
    // the real schema would refuse.
    $client->organization_id = 0;

    Auth::guard('api-m2m')->setUser($client);

    $this->getJson('/api/v1/_probe/tenant-context-only')
        ->assertUnauthorized();
});

test('a valid client → resolver stamped, bypass cleared, ApiMode set', function (): void {
    $org = Organization::factory()->create();
    $client = ApiClient::factory()->create(['organization_id' => $org->id, 'mode' => 'test']);

    Auth::guard('api-m2m')->setUser($client);

    $this->getJson('/api/v1/_probe/tenant-context-only')
        ->assertOk()
        ->assertJsonPath('org_id', $org->id)
        ->assertJsonPath('bypass', false)
        ->assertJsonPath('mode', 'test');
});
