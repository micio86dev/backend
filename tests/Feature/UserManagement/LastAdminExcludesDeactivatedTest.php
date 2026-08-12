<?php

declare(strict_types=1);

/**
 * The last-admin guard counts rows in `model_has_roles`, and deactivation does
 * NOT remove a role assignment — it only stamps `deactivated_at`. So a
 * deactivated admin keeps counting toward "an admin survives", and the guard
 * waves through the demotion of the only admin who can still authenticate.
 *
 * The result is an organization locked out of its own backoffice with no
 * self-service recovery: the deactivated admin is refused by TenantContext on
 * every request including login, and the demoted one no longer holds the role.
 * An availability guard that counts users who cannot log in is not counting
 * administrators.
 *
 * REQ: Last-Admin And Self-Action Guards
 *      (openspec/changes/backoffice-missing-pages/specs/user-management/spec.md)
 */

use App\Models\Organization;
use App\Models\User;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

test('a deactivated admin does not count toward the surviving-admin check', function (): void {
    $org = Organization::factory()->create();
    ['user' => $active, 'token' => $token] = authUserAndTokenForRole($org, 'admin');

    // A second admin who has since been deactivated. Their model_has_roles row
    // survives deactivation by design — the role is not revoked.
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $deactivated = User::factory()->create(['organization_id' => $org->id]);
    $deactivated->assignRole(
        SpatieRole::where(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id])->firstOrFail()
    );
    $deactivated->forceFill(['deactivated_at' => now()])->save();

    // Exactly one admin can still authenticate, so this demotion must be refused.
    $this->withToken($token)
        ->patchJson("/api/users/{$active->id}", ['role' => 'viewer'])
        ->assertStatus(422);

    expect($active->fresh()->hasRole('admin'))->toBeTrue();
});

test('a deactivated admin does not license deactivating the last usable admin', function (): void {
    $org = Organization::factory()->create();
    ['user' => $active, 'token' => $token] = authUserAndTokenForRole($org, 'admin');

    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $deactivated = User::factory()->create(['organization_id' => $org->id]);
    $deactivated->assignRole(
        SpatieRole::where(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id])->firstOrFail()
    );
    $deactivated->forceFill(['deactivated_at' => now()])->save();

    $this->withToken($token)
        ->postJson("/api/users/{$active->id}/deactivate")
        ->assertStatus(422);

    expect($active->fresh()->deactivated_at)->toBeNull();
});
