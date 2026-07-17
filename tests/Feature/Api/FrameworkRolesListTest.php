<?php

/**
 * RED — 11.1: GET /api/framework/roles returns all 5 global roles (C3).
 *
 * Authenticated org-A user calls GET /api/framework/roles;
 * asserts 200, all 5 roles returned, no org-B data.
 *
 * Refs spec: "Org A user lists roles".
 */

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder())->run();
});

test('authenticated user gets all 5 global roles with 200', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles');

    $response->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonStructure(['data' => [['code', 'name', 'responsibilities', 'competency_count']]]);

    $codes = collect($response->json('data'))->pluck('code')->sort()->values()->toArray();
    expect($codes)->toBe(['BUL', 'FLL', 'ICO', 'MLL', 'SRX']);
});

test('response does not leak data from another organization', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $userA = User::factory()->create(['organization_id' => $orgA->id]);
    $token = auth('api')->login($userA);

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles');

    // Global catalog serves all 5 roles for both orgs — no org-B specific data leaks
    $response->assertOk()->assertJsonCount(5, 'data');
});
