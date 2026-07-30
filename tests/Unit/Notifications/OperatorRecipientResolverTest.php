<?php

declare(strict_types=1);

/**
 * OperatorRecipientResolver (C12, D2) — held to ~95%.
 *
 * This is the highest-risk unit in C12: it is the one place where a missing
 * filter becomes a cross-tenant disclosure delivered by email.
 */

use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\OperatorRecipientResolver;
use Spatie\Permission\Models\Role as AuthorizationRole;
use Spatie\Permission\PermissionRegistrar;

function makeOrgUserWithRole(Organization $org, ?string $roleName, array $attributes = []): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);

    $user = User::factory()->create(array_merge([
        'organization_id' => $org->id,
    ], $attributes));

    if ($roleName !== null) {
        $role = AuthorizationRole::firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'api',
            'team_id' => $org->id,
        ]);
        $user->assignRole($role);
    }

    return $user;
}

test('returns users holding a configured recipient role', function (): void {
    $org = Organization::factory()->create();

    $admin = makeOrgUserWithRole($org, 'admin');
    $operator = makeOrgUserWithRole($org, 'operator');

    $recipients = (new OperatorRecipientResolver)->forOrganization($org->id);

    expect($recipients->pluck('id')->sort()->values()->all())
        ->toBe(collect([$admin->id, $operator->id])->sort()->values()->all());
});

test('excludes a role that is not configured as a recipient', function (): void {
    $org = Organization::factory()->create();

    makeOrgUserWithRole($org, 'viewer');

    // Read access to a dashboard is not a reason to be paged.
    expect((new OperatorRecipientResolver)->forOrganization($org->id))->toBeEmpty();
});

test('excludes a user of the organization holding no role at all', function (): void {
    $org = Organization::factory()->create();
    makeOrgUserWithRole($org, null);

    expect((new OperatorRecipientResolver)->forOrganization($org->id))->toBeEmpty();
});

test('a missing role row yields an empty set, NOT a RoleDoesNotExist exception', function (): void {
    // THE Spatie landmine. Passing role NAMES to ->role() makes scopeRole
    // resolve each via Role::findByName, which THROWS when the row is absent.
    // Role rows are per-organization, so a fresh org that never had an
    // `operator` row would make this query throw inside the alerting job —
    // retry, retry, dead job, and the operator never learns their integration
    // is broken. An alerting path must not be the thing that breaks.
    $org = Organization::factory()->create();

    // Only `admin` exists for this org; `operator` is configured but absent.
    $admin = makeOrgUserWithRole($org, 'admin');

    $recipients = (new OperatorRecipientResolver)->forOrganization($org->id);

    expect($recipients->pluck('id')->all())->toBe([$admin->id]);
});

test('an organization with no recipient roles at all yields an empty set', function (): void {
    $org = Organization::factory()->create();
    User::factory()->create(['organization_id' => $org->id]);

    // Neither `admin` nor `operator` exists for this org. Empty, not thrown.
    expect((new OperatorRecipientResolver)->forOrganization($org->id))->toBeEmpty();
});

test('a platform superadmin with a null organization_id is never returned', function (): void {
    $org = Organization::factory()->create();
    $operator = makeOrgUserWithRole($org, 'operator');

    // organization_id is nullable, and `where(col, value)` never matches NULL.
    // The exclusion is by construction rather than by an explicit condition,
    // which is exactly why it needs an explicit test.
    User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

    $recipients = (new OperatorRecipientResolver)->forOrganization($org->id);

    expect($recipients->pluck('id')->all())->toBe([$operator->id]);
});

test('users of another organization are never returned', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $inA = makeOrgUserWithRole($orgA, 'admin');
    makeOrgUserWithRole($orgB, 'admin');

    expect((new OperatorRecipientResolver)->forOrganization($orgA->id)->pluck('id')->all())
        ->toBe([$inA->id]);
});

test('an empty configured role list yields an empty set without querying roles', function (): void {
    $org = Organization::factory()->create();
    makeOrgUserWithRole($org, 'admin');

    config()->set('notifications.recipients.roles', []);

    expect((new OperatorRecipientResolver)->forOrganization($org->id))->toBeEmpty();
});

test('the configured guard is honoured rather than inferred', function (): void {
    $org = Organization::factory()->create();
    makeOrgUserWithRole($org, 'admin');

    // Both `web` and `api` use the users provider, so a wrong guard matches
    // zero roles silently — an alert that never arrives, with no error.
    config()->set('notifications.recipients.guard', 'web');

    expect((new OperatorRecipientResolver)->forOrganization($org->id))->toBeEmpty();
});
