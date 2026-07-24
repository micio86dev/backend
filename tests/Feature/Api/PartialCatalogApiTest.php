<?php

/**
 * RED — 11.6: API responds correctly with partial catalog (C3).
 *
 * SRX BARS absent → GET /api/framework/roles still returns 200 with SRX listed.
 * Refs spec: "API responds correctly with partial catalog".
 */

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run(); // SRX has no bars — partial catalog state
});

test('GET /api/framework/roles returns 200 with SRX included even when SRX has no BARS', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles');

    $response->assertOk()
        ->assertJsonCount(5, 'data');

    $codes = collect($response->json('data'))->pluck('code')->toArray();
    expect($codes)->toContain('SRX');
});

test('GET /api/framework/roles does not return 500 with partial catalog', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles');

    $response->assertStatus(200);
});
