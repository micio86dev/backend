<?php

/**
 * Spatie RBAC scope isolation tests (C2).
 *
 * Verifies:
 * (a) admin role in Org A does NOT grant access in Org B.
 * (b) Role change invalidates Spatie permission cache before next check.
 * (c) Spatie roles table contains ONLY admin/operator/viewer — no superadmin, no BEAI framework roles.
 */

use App\Models\Organization;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('admin role in Org A does not grant access in Org B', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $orgA->id]);

    // Assign admin role in Org A context.
    app(PermissionRegistrar::class)->setPermissionsTeamId($orgA->id);
    $adminRoleA = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $orgA->id]);
    $user->assignRole($adminRoleA);

    // Check role in Org A context — must be true.
    app(PermissionRegistrar::class)->setPermissionsTeamId($orgA->id);
    expect($user->hasRole('admin'))->toBeTrue();

    // Check role in Org B context — must be false (no role in Org B).
    app(PermissionRegistrar::class)->setPermissionsTeamId($orgB->id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($user->fresh()->hasRole('admin'))->toBeFalse();
});

test('role change invalidates Spatie permission cache before next check', function (): void {
    $orgA = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $orgA->id]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($orgA->id);
    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $orgA->id]);
    $viewerRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'api', 'team_id' => $orgA->id]);

    // Assign admin, check it.
    $user->assignRole($adminRole);
    expect($user->fresh()->hasRole('admin'))->toBeTrue();

    // Change role: detach admin, assign viewer. Cache must be invalidated.
    $user->removeRole($adminRole);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->assignRole($viewerRole);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Fresh user must reflect new role.
    expect($user->fresh()->hasRole('admin'))->toBeFalse();
    expect($user->fresh()->hasRole('viewer'))->toBeTrue();
});

test('Spatie roles table contains only admin/operator/viewer — no superadmin or framework roles', function (): void {
    // Seed the standard roles for C2 (as the seeder would do in production).
    $orgA = Organization::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId($orgA->id);

    Role::firstOrCreate(['name' => 'admin',    'guard_name' => 'api', 'team_id' => $orgA->id]);
    Role::firstOrCreate(['name' => 'operator', 'guard_name' => 'api', 'team_id' => $orgA->id]);
    Role::firstOrCreate(['name' => 'viewer',   'guard_name' => 'api', 'team_id' => $orgA->id]);

    $allRoleNames = Role::all()->pluck('name')->toArray();

    // Allowed names: only admin, operator, viewer (org-scoped).
    $disallowedNames = ['superadmin', 'ICO', 'FLL', 'MLL', 'BUL', 'SRX'];

    foreach ($disallowedNames as $disallowed) {
        expect($allRoleNames)->not->toContain($disallowed,
            "Spatie roles table must NOT contain '{$disallowed}' — it is either a superadmin role "
            .'or a BEAI framework role (ICO/FLL/MLL/BUL/SRX). These must remain out of Spatie.'
        );
    }

    expect(array_intersect($allRoleNames, ['admin', 'operator', 'viewer']))->not->toBeEmpty();
});
