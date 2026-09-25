<?php

declare(strict_types=1);

/**
 * `GET /v1/organization` — BEAI Public API (public-api step 4), SPEC.md
 * §3.3, `openapi.yaml`'s `getOrganization` operation.
 */

use App\Enums\ApiKeyMode;
use App\Http\Controllers\PublicApi\OrganizationController;
use App\Http\Middleware\PublicApi\AuthenticatePublicApi;
use App\Models\ApiClient;
use App\Models\Organization;
use App\Services\ApiKeyGenerator;
use App\Support\PublicApi\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

// Sits directly under Feature/PublicApi/ (like HealthEndpointTest.php,
// which stays DB-free) — this file DOES create rows via factories, so it
// declares RefreshDatabase itself rather than earning a blanket
// `Feature/PublicApi` entry in tests/Pest.php (see that file's own
// comment above the `Feature/PublicApi/Auth` registration).
uses(RefreshDatabase::class);

test('GET /v1/organization returns exactly the contract field set, no extra scope required', function (): void {
    $org = Organization::factory()->create(['name' => 'Acme Corp', 'allowed_domains' => ['acme.example']]);
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id, 'abilities' => []]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/organization');

    $response->assertOk();
    expect(array_keys($response->json()))->toEqualCanonicalizing(['id', 'name', 'mode', 'allowed_domains', 'created_at']);
    expect($response->json('id'))->toBe(PublicId::encode($org->fresh()));
    expect($response->json('name'))->toBe('Acme Corp');
    expect($response->json('mode'))->toBe('live');
    expect($response->json('allowed_domains'))->toBe(['acme.example']);

    $this->assertMatchesContract($response, 'GET', '/organization');
});

test('mode reflects the authenticated key, not a fixed value', function (): void {
    $org = Organization::factory()->create();
    $testKey = ApiKeyGenerator::generate(ApiKeyMode::Test);
    ApiClient::factory()->withRawKey($testKey)->create(['organization_id' => $org->id, 'mode' => 'test', 'abilities' => []]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$testKey])->getJson('/api/v1/organization');

    $response->assertOk()->assertJsonPath('mode', 'test');
});

test('allowed_domains renders [] when the organization configured none', function (): void {
    $org = Organization::factory()->create(['allowed_domains' => null]);
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id, 'abilities' => []]);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/organization');

    $response->assertOk()->assertJsonPath('allowed_domains', []);
});

test('an unauthenticated request → 401 invalid_api_key', function (): void {
    $response = $this->getJson('/api/v1/organization');

    $response->assertStatus(401)->assertJsonPath('code', 'invalid_api_key');
    $this->assertProblemMatchesContract($response, 401);
});

test('defensive branch: a null/unresolvable org id → 404, never trusted blindly', function (): void {
    // Unreachable through the real /v1 stack — PublicApiTenantContext
    // already 401s before this controller ever runs when the org id is
    // missing — but OrganizationController checks explicitly rather than
    // trusting it, the same discipline RateLimitPublicApi's own
    // "a request with no resolved public_api.client" test exercises via a
    // probe missing the middleware that would normally guarantee it.
    // AuthenticatePublicApi still runs (the controller reads $client->mode
    // unconditionally), but PublicApiTenantContext — the ONLY thing that
    // ever stamps TenantResolver's org id — is deliberately absent.
    Route::middleware([AuthenticatePublicApi::class])->prefix('api/v1')->group(function (): void {
        Route::get('/_probe/organization', [OrganizationController::class, 'show']);
    });

    $org = Organization::factory()->create();
    $rawKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($rawKey)->create(['organization_id' => $org->id, 'abilities' => []]);

    $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/_probe/organization')
        ->assertNotFound();
});
