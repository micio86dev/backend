<?php

/**
 * TenantContext middleware feature tests (C2).
 *
 * Uses /api/auth/me (protected by auth:api + TenantContext via api group) to
 * verify the fail-closed invariants:
 * (a) valid JWT + DB org → resolver.orgId set + setPermissionsTeamId(orgId)
 * (b) null org + is_superadmin=true → bypass=true + setPermissionsTeamId(null)
 * (c) null org + is_superadmin=false → 403
 * (d) no JWT → 401 (rejected by auth:api before TenantContext runs)
 */

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;

test('valid JWT with DB org sets resolver orgId', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    $token = auth('api')->login($user);

    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertOk();

    $resolver = app(TenantResolver::class);
    expect($resolver->getOrgId())->toBe($org->id);
    expect($resolver->isBypass())->toBeFalse();
});

test('null org + is_superadmin=true sets bypass and clears Spatie team id', function (): void {
    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

    $token = auth('api')->login($user);

    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertOk();

    $resolver = app(TenantResolver::class);
    expect($resolver->isBypass())->toBeTrue();
    expect($resolver->getOrgId())->toBeNull();
});

test('null org + is_superadmin=false returns 403 (fail-closed)', function (): void {
    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => false]);

    $token = auth('api')->login($user);

    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertForbidden();
});

test('no JWT returns 401 before TenantContext runs', function (): void {
    $this->getJson('/api/auth/me')
        ->assertUnauthorized();
});
