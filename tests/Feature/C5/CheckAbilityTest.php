<?php

declare(strict_types=1);

/**
 * CheckAbility middleware feature tests (C5 — M2M API Authentication).
 *
 * Asserts via real HTTP routes:
 * - authenticated client with required ability → 200
 * - authenticated client without required ability → 403
 * - whoami route (no ability required) → 200 with no abilities
 *
 * REQ-6 / design §CheckAbility
 */

use App\Http\Middleware\TenantContext;
use App\Http\Middleware\TenantContextM2m;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Services\ApiKeyGenerator;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    // Register a test route requiring 'evaluations:read' ability
    Route::prefix('api')
        ->withoutMiddleware(TenantContext::class)
        ->middleware(['auth:api-m2m', TenantContextM2m::class, SubstituteBindings::class])
        ->group(function (): void {
            Route::middleware('ability:evaluations:read')
                ->get('/test-ability-check', fn () => response()->json(['ok' => true]));
        });
});

test('client with required ability → 200', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash' => ApiKeyGenerator::hash($rawKey),
        'abilities' => ['evaluations:read', 'participants:read'],
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/test-ability-check')
        ->assertOk();
});

test('client without required ability → 403', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash' => ApiKeyGenerator::hash($rawKey),
        'abilities' => ['participants:read'],  // no evaluations:read
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/test-ability-check')
        ->assertForbidden();
});

test('whoami route requires no ability — client with empty abilities gets 200', function (): void {
    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate();

    ApiClient::factory()->create([
        'organization_id' => $org->id,
        'key_hash' => ApiKeyGenerator::hash($rawKey),
        'abilities' => [],  // no abilities at all
    ]);

    // whoami has no ability middleware — should succeed with auth alone
    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/m2m/whoami')
        ->assertOk();
});
