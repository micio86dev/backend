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
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function makeC5PolicyUser(Organization $org, string $role): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
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

    // admin of org A cannot delete a client belonging to org B
    expect($policy->delete($adminA, $clientB))->toBeFalse();
});
