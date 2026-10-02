<?php

declare(strict_types=1);

/**
 * `EvaluationPolicy::viewAny()` / `view()` — the read abilities. All three
 * organizational roles may read; a user holding NO role may not. Mirrors
 * `EvaluationPolicyAuditTest`'s direct-instantiation shape (no HTTP, no Gate
 * facade). `view()` is not invoked by application code today (the controllers
 * authorize `viewAny`), so this is the only place its contract is pinned.
 */

use App\Models\Evaluation;
use App\Models\Organization;
use App\Models\User;
use App\Policies\EvaluationPolicy;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function evaluationReadPolicyUser(Organization $org, ?string $roleName): User
{
    $user = User::factory()->create(['organization_id' => $org->id]);

    if ($roleName !== null) {
        app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
        $spatieRole = SpatieRole::firstOrCreate(['name' => $roleName, 'guard_name' => 'api', 'team_id' => $org->id]);
        $user->assignRole($spatieRole);
    }

    return $user->fresh();
}

test('viewAny and view are allowed for admin, operator and viewer', function (string $role): void {
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);
    $user = evaluationReadPolicyUser($org, $role);
    $policy = new EvaluationPolicy;

    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->view($user, new Evaluation))->toBeTrue();
})->with(['admin', 'operator', 'viewer']);

test('viewAny and view are refused for a user with no role', function (): void {
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);
    $user = evaluationReadPolicyUser($org, null);
    $policy = new EvaluationPolicy;

    expect($policy->viewAny($user))->toBeFalse()
        ->and($policy->view($user, new Evaluation))->toBeFalse();
});

test('a role held in another organization grants no read access here', function (): void {
    $home = Organization::factory()->create();
    $other = Organization::factory()->create();
    $user = evaluationReadPolicyUser($home, 'admin');

    // Teams mode: the permission team is the ambient organization. An admin of
    // `home` evaluated under `other`'s team context holds no role there.
    app(PermissionRegistrar::class)->setPermissionsTeamId($other->id);
    $user = $user->fresh();
    $policy = new EvaluationPolicy;

    expect($policy->viewAny($user))->toBeFalse()
        ->and($policy->view($user, new Evaluation))->toBeFalse();
});
