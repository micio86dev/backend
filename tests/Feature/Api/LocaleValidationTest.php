<?php

/**
 * RED — S-2: Unsupported ?locale= returns 422.
 *
 * Approved design: "?locale= query param is validated ∈ config('app.supported_locales')=['it','en']".
 * An explicit unsupported ?locale= MUST return HTTP 422 (validation error), NOT a silent fallback.
 *
 * IMPORTANT distinction:
 *   - ?locale=fr  → 422  (explicit unsupported locale)
 *   - ?locale=en  → 200  (supported)
 *   - ?locale=it  → 200  (supported)
 *   - Accept-Language: fr (no ?locale) → 200 with EN fallback (headers are advisory, never 422)
 *
 * All three framework endpoints are covered (roles, role-competencies, indicators).
 *
 * Refs design: "Locale resolution order — ?locale= validated ∈ config('app.supported_locales')".
 */

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
});

// ---------------------------------------------------------------------------
// S-2a: GET /api/framework/roles — unsupported ?locale= → 422
// ---------------------------------------------------------------------------

test('GET roles with unsupported ?locale=fr returns 422', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles?locale=fr');

    $response->assertStatus(422)
        ->assertJsonValidationErrorFor('locale');
});

test('GET roles with ?locale=en returns 200', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles?locale=en');

    $response->assertOk();
});

test('GET roles with ?locale=it returns 200', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles?locale=it');

    $response->assertOk();
});

test('GET roles with Accept-Language: fr header (no ?locale) returns 200 with EN fallback, NOT 422', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    // Headers are advisory — unsupported header value MUST NOT trigger 422
    $response = $this->withToken($token)
        ->withHeaders(['Accept-Language' => 'fr'])
        ->getJson('/api/framework/roles');

    $response->assertOk();

    // Response must be non-empty (EN fallback served)
    $response->assertJsonCount(5, 'data');
});

// ---------------------------------------------------------------------------
// S-2b: GET /api/framework/roles/{role}/competencies — unsupported ?locale= → 422
// ---------------------------------------------------------------------------

test('GET role-competencies with unsupported ?locale=de returns 422', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles/ICO/competencies?locale=de');

    $response->assertStatus(422)
        ->assertJsonValidationErrorFor('locale');
});

// ---------------------------------------------------------------------------
// S-2c: GET /api/framework/roles/{role}/competencies/{comp}/indicators — unsupported ?locale= → 422
// ---------------------------------------------------------------------------

test('GET indicators with unsupported ?locale=es returns 422', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles/ICO/competencies/PRS/indicators?locale=es');

    $response->assertStatus(422)
        ->assertJsonValidationErrorFor('locale');
});
