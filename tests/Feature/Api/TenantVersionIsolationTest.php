<?php

/**
 * RED — 11.3: Tenant version isolation on framework_versions rows (C3).
 *
 * Org A and org B each create their own FrameworkVersion;
 * asserts row-level TenantScoped isolation AND same global roles for both.
 *
 * Refs spec: "Two organizations pin different framework versions".
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
});

test('each org sees only their own FrameworkVersion row', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $resolver = app(TenantResolver::class);

    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);
    FrameworkVersion::create(['version' => 'v1', 'is_locked' => false]);

    $resolver->setOrgId($orgB->id);
    FrameworkVersion::create(['version' => 'v2', 'is_locked' => false]);

    // Org A sees only their version
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);
    $orgAVersions = FrameworkVersion::all();
    expect($orgAVersions)->toHaveCount(1)
        ->and($orgAVersions->first()->version)->toBe('v1');

    // Org B sees only their version
    $resolver->setOrgId($orgB->id);
    $orgBVersions = FrameworkVersion::all();
    expect($orgBVersions)->toHaveCount(1)
        ->and($orgBVersions->first()->version)->toBe('v2');
});

test('both orgs see the same 5 global roles via API', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $userA = User::factory()->create(['organization_id' => $orgA->id]);
    $userB = User::factory()->create(['organization_id' => $orgB->id]);

    $tokenA = auth('api')->login($userA);
    $tokenB = auth('api')->login($userB);

    $responseA = $this->withToken($tokenA)->getJson('/api/framework/roles');
    $responseB = $this->withToken($tokenB)->getJson('/api/framework/roles');

    $responseA->assertOk()->assertJsonCount(5, 'data');
    $responseB->assertOk()->assertJsonCount(5, 'data');

    // Same roles for both
    $codesA = collect($responseA->json('data'))->pluck('code')->sort()->values()->toArray();
    $codesB = collect($responseB->json('data'))->pluck('code')->sort()->values()->toArray();
    expect($codesA)->toBe($codesB);
});
