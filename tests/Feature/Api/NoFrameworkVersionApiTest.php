<?php

/**
 * RED — 11.7: Org with no FrameworkVersion still receives global catalog → 200 (C3).
 *
 * Refs spec: "Org with no pinned FrameworkVersion still receives the global catalog → 200".
 */

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
});

test('org with zero FrameworkVersion rows gets 200 and all 5 roles', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    // Note: no FrameworkVersion created for this org

    $token = auth('api')->login($user);

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles');

    $response->assertOk()
        ->assertJsonCount(5, 'data');
});

test('pin_context is null when no FrameworkVersion exists for the org', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $token = auth('api')->login($user);

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles');

    $response->assertOk();
    expect($response->json('pin_context'))->toBeNull();
});
