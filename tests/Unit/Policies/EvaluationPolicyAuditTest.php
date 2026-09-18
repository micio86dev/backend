<?php

declare(strict_types=1);

/**
 * RED — P4.1: `EvaluationPolicy::audit()` RBAC gate (scoring-audit-jev,
 * design D12). Admin ONLY — diverging from `viewAny`/`view` on the same
 * policy, which admit all three roles. Mirrors
 * `tests/Unit/C4/ProjectPolicyTest.php`'s direct-instantiation shape:
 * exercises the policy method directly, no HTTP, no Gate facade.
 */

use App\Models\Organization;
use App\Models\User;
use App\Policies\EvaluationPolicy;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function evaluationAuditPolicyUser(Organization $org, string $roleName): User
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $spatieRole = SpatieRole::firstOrCreate(['name' => $roleName, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return $user->fresh();
}

test('admin may trigger an audit', function (): void {
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);
    $admin = evaluationAuditPolicyUser($org, 'admin');
    $policy = new EvaluationPolicy;

    expect($policy->audit($admin))->toBeTrue();
});

test('operator is refused — auditing is discretionary spend, not a read', function (): void {
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);
    $operator = evaluationAuditPolicyUser($org, 'operator');
    $policy = new EvaluationPolicy;

    expect($policy->audit($operator))->toBeFalse();
});

test('viewer is refused', function (): void {
    $org = Organization::factory()->create();
    app(TenantResolver::class)->setOrgId($org->id);
    $viewer = evaluationAuditPolicyUser($org, 'viewer');
    $policy = new EvaluationPolicy;

    expect($policy->audit($viewer))->toBeFalse();
});
