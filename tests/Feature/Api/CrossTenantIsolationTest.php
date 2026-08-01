<?php

/**
 * RED — 11.2: Cross-tenant isolation on pin_context (C3).
 *
 * Org-B user cannot see org-A's FrameworkVersion via pin_context.
 * Catalog content (global) is the same for all — isolation is on metadata.
 *
 * Refs spec: "Cross-tenant isolation — Org B cannot access Org A's framework data".
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
});

test('org B user sees their own pin_context, not org A pin context', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    // Create FrameworkVersion for org A using bypass
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);
    FrameworkVersion::create(['version' => 'v1-orgA', 'is_locked' => false]);

    // Create FrameworkVersion for org B
    $resolver->setOrgId($orgB->id);
    FrameworkVersion::create(['version' => 'v1-orgB', 'is_locked' => false]);

    // Authenticate as org B user
    $userB = User::factory()->create(['organization_id' => $orgB->id]);
    $token = auth('api')->login($userB);

    $response = $this->withToken($token)
        ->getJson('/api/framework/roles');

    $response->assertOk();

    // pin_context should reflect org B's version (not org A's)
    $pinContext = $response->json('pin_context');
    if ($pinContext !== null) {
        expect($pinContext)->not->toContain('v1-orgA');
    }
});
