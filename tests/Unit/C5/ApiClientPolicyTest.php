<?php

declare(strict_types=1);

/**
 * ApiClientPolicy unit tests (C5 — M2M API Authentication).
 *
 * Asserts:
 * - admin → create/viewAny/delete allowed
 * - operator → 403 for all
 * - viewer → 403 for all
 * - cross-org admin → 403 for delete
 *
 * REQ-8
 */

use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\User;
use App\Policies\ApiClientPolicy;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * An admin/operator/viewer of `$org`, WITH the tenant context a request would
 * have carried.
 *
 * `delete()` now asks the resolver which organization the request is scoped
 * to rather than reading the actor's own column, because for a superadmin
 * acting as a client those two disagree — `TenantContext` narrows the resolver
 * and leaves `users.organization_id` null. `TenantContext` is middleware, so a
 * unit test that calls the policy directly has to stand in for it; without
 * this line the resolver is at its fail-closed default (orgId null) and the
 * policy correctly refuses everything.
 */
function makeC5PolicyUser(Organization $org, string $role): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    app(TenantResolver::class)->setOrgId((int) $org->id);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $spatieRole = SpatieRole::firstOrCreate([
        'name' => $role,
        'guard_name' => 'api',
        'team_id' => $org->id,
    ]);
    $user->assignRole($spatieRole);

    return $user;
}

test('admin can viewAny', function (): void {
    $org = Organization::factory()->create();
    $user = makeC5PolicyUser($org, 'admin');
    $policy = new ApiClientPolicy;

    expect($policy->viewAny($user))->toBeTrue();
});

test('admin can create', function (): void {
    $org = Organization::factory()->create();
    $user = makeC5PolicyUser($org, 'admin');
    $policy = new ApiClientPolicy;

    expect($policy->create($user))->toBeTrue();
});

test('admin can delete own-org client', function (): void {
    $org = Organization::factory()->create();
    $user = makeC5PolicyUser($org, 'admin');
    $client = ApiClient::factory()->create(['organization_id' => $org->id]);
    $policy = new ApiClientPolicy;

    expect($policy->delete($user, $client))->toBeTrue();
});

test('operator cannot viewAny', function (): void {
    $org = Organization::factory()->create();
    $user = makeC5PolicyUser($org, 'operator');
    $policy = new ApiClientPolicy;

    expect($policy->viewAny($user))->toBeFalse();
});

test('operator cannot create', function (): void {
    $org = Organization::factory()->create();
    $user = makeC5PolicyUser($org, 'operator');
    $policy = new ApiClientPolicy;

    expect($policy->create($user))->toBeFalse();
});

test('operator cannot delete', function (): void {
    $org = Organization::factory()->create();
    $user = makeC5PolicyUser($org, 'operator');
    $client = ApiClient::factory()->create(['organization_id' => $org->id]);
    $policy = new ApiClientPolicy;

    expect($policy->delete($user, $client))->toBeFalse();
});

test('viewer cannot viewAny', function (): void {
    $org = Organization::factory()->create();
    $user = makeC5PolicyUser($org, 'viewer');
    $policy = new ApiClientPolicy;

    expect($policy->viewAny($user))->toBeFalse();
});

test('cross-org admin cannot delete another org client', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $adminA = makeC5PolicyUser($orgA, 'admin');
    $clientB = ApiClient::factory()->create(['organization_id' => $orgB->id]);
    $policy = new ApiClientPolicy;

    // The context stays org A — the actor's. `ApiClient` is not a TenantModel,
    // so creating org B's row above does not move it.
    app(TenantResolver::class)->setOrgId((int) $orgA->id);

    // admin of org A cannot delete a client belonging to org B
    expect($policy->delete($adminA, $clientB))->toBeFalse();
});

test('no tenant context refuses the delete outright', function (): void {
    $org = Organization::factory()->create();
    $user = makeC5PolicyUser($org, 'admin');
    $client = ApiClient::factory()->create(['organization_id' => $org->id]);
    $policy = new ApiClientPolicy;

    // The all-clients view: a superadmin with no client selected. Fails
    // closed — there is no organization to compare the row against, so there
    // is no basis on which to allow the revocation.
    app(TenantResolver::class)->setOrgId(null);

    expect($policy->delete($user, $client))->toBeFalse();
});
