<?php

declare(strict_types=1);

/**
 * RED — 6.1: ProjectPolicy RBAC gates (C4).
 *
 * admin → all CRUD; operator → all CRUD; viewer → viewAny/view only (create/update/delete → denied).
 *
 * Refs spec: RBAC Gates.
 */

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function makePolicyUser(Organization $org, string $roleName): User
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $spatieRole = SpatieRole::firstOrCreate(['name' => $roleName, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    // Force-refresh user so hasRole() picks up the assignment
    return $user->fresh();
}

test('admin can viewAny, view, create, update, delete', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $admin = makePolicyUser($org, 'admin');
    $project = Project::factory()->create();
    $policy = new ProjectPolicy;

    expect($policy->viewAny($admin))->toBeTrue();
    expect($policy->view($admin, $project))->toBeTrue();
    expect($policy->create($admin))->toBeTrue();
    expect($policy->update($admin, $project))->toBeTrue();
    expect($policy->delete($admin, $project))->toBeTrue();
});

test('operator can viewAny, view, create, update, delete', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $operator = makePolicyUser($org, 'operator');
    $project = Project::factory()->create();
    $policy = new ProjectPolicy;

    expect($policy->viewAny($operator))->toBeTrue();
    expect($policy->view($operator, $project))->toBeTrue();
    expect($policy->create($operator))->toBeTrue();
    expect($policy->update($operator, $project))->toBeTrue();
    expect($policy->delete($operator, $project))->toBeTrue();
});

test('viewer can viewAny and view but NOT create, update, or delete', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $viewer = makePolicyUser($org, 'viewer');
    $project = Project::factory()->create();
    $policy = new ProjectPolicy;

    expect($policy->viewAny($viewer))->toBeTrue();
    expect($policy->view($viewer, $project))->toBeTrue();
    expect($policy->create($viewer))->toBeFalse();
    expect($policy->update($viewer, $project))->toBeFalse();
    expect($policy->delete($viewer, $project))->toBeFalse();
});
