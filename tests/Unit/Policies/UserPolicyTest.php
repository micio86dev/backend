<?php

declare(strict_types=1);

/**
 * User management is a privilege-escalation surface: every ability is
 * admin-only, with no read access for operators or viewers.
 */

use App\Models\Organization;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function userPolicyActor(Organization $org, ?string $roleName): User
{
    $user = User::factory()->create(['organization_id' => $org->id]);

    if ($roleName !== null) {
        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        $user->assignRole(SpatieRole::firstOrCreate(['name' => $roleName, 'guard_name' => 'api', 'team_id' => $org->id]));
    }

    return $user->fresh();
}

test('only an admin may do anything to users', function (?string $role, bool $allowed): void {
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);
    $actor = userPolicyActor($org, $role);
    $target = User::factory()->create(['organization_id' => $org->id]);
    $policy = new UserPolicy;

    expect($policy->viewAny($actor))->toBe($allowed)
        ->and($policy->view($actor, $target))->toBe($allowed)
        ->and($policy->create($actor))->toBe($allowed)
        ->and($policy->update($actor, $target))->toBe($allowed)
        ->and($policy->deactivate($actor, $target))->toBe($allowed)
        ->and($policy->activate($actor, $target))->toBe($allowed);
})->with([
    'admin' => ['admin', true],
    'operator' => ['operator', false],
    'viewer' => ['viewer', false],
    'no role' => [null, false],
]);
