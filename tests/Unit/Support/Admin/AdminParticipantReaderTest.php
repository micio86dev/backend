<?php

declare(strict_types=1);

/**
 * RED → GREEN — AdminParticipantReader (C11, task 2.2).
 *
 * The single sanctioned path to obtain a Participant for an admin HTTP read
 * (D1): org filter → RBAC → lifecycle gate, in that fixed order, so the
 * failure a caller sees always means exactly one thing — 404 not yours, 403
 * not your role, 409 not yet.
 *
 * REQ: Cross-Tenant Isolation on Every Admin Read Endpoint,
 *      Lifecycle Read-Gate (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md)
 */

use App\Exceptions\Admin\LifecycleNotReadyException;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Admin\AdminParticipantReader;
use App\Support\Admin\ParticipantReadScope;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

/**
 * Create an admin-side User in $org, with an optional Spatie role assigned.
 * $roleName === null means "no role at all", for the RBAC-denial case.
 */
function createAdminUser(Organization $org, ?string $roleName): User
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);

    if ($roleName !== null) {
        $role = SpatieRole::firstOrCreate(['name' => $roleName, 'guard_name' => 'api', 'team_id' => $org->id]);
        $user->assignRole($role);
    }

    return $user;
}

/**
 * Create a Participant in $org with the given status. Sets the TenantResolver
 * to $org while creating the FrameworkVersion/Project chain (both TenantModel,
 * stamped by TenantScoped.creating).
 */
function createParticipantIn(Organization $org, string $status): Participant
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);

    return Participant::factory()->forProject($project)->withStatus($status)->create();
}

test('cross-org id raises ModelNotFoundException before RBAC or the lifecycle gate ever run', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $participantInOrgB = createParticipantIn($orgB, 'completato');
    $adminInOrgA = createAdminUser($orgA, 'admin');

    app(TenantResolver::class)->setOrgId($orgA->id);
    actingAs($adminInOrgA, 'api');

    $reader = new AdminParticipantReader(app(TenantResolver::class));

    expect(fn () => $reader->read($participantInOrgB->id, ParticipantReadScope::Summary))
        ->toThrow(ModelNotFoundException::class);
});

test('same-org RBAC-denying user gets an authorization denial, never a leaked read', function (): void {
    $org = Organization::factory()->create();
    $participant = createParticipantIn($org, 'completato');
    $userWithNoRole = createAdminUser($org, null);

    app(TenantResolver::class)->setOrgId($org->id);
    actingAs($userWithNoRole, 'api');

    $reader = new AdminParticipantReader(app(TenantResolver::class));

    expect(fn () => $reader->read($participant->id, ParticipantReadScope::Summary))
        ->toThrow(AuthorizationException::class);
});

test('same-org, authorized, but gate-blocked status raises LifecycleNotReadyException', function (): void {
    $org = Organization::factory()->create();
    $participant = createParticipantIn($org, 'in_corso');
    $viewer = createAdminUser($org, 'viewer');

    app(TenantResolver::class)->setOrgId($org->id);
    actingAs($viewer, 'api');

    $reader = new AdminParticipantReader(app(TenantResolver::class));

    expect(fn () => $reader->read($participant->id, ParticipantReadScope::Transcript))
        ->toThrow(LifecycleNotReadyException::class);
});

test('same-org, authorized, ready status returns the Participant', function (): void {
    $org = Organization::factory()->create();
    $participant = createParticipantIn($org, 'completato');
    $operator = createAdminUser($org, 'operator');

    app(TenantResolver::class)->setOrgId($org->id);
    actingAs($operator, 'api');

    $reader = new AdminParticipantReader(app(TenantResolver::class));

    $result = $reader->read($participant->id, ParticipantReadScope::Evaluation);

    expect($result)->toBeInstanceOf(Participant::class);
    expect($result->id)->toBe($participant->id);
    expect($result->organization_id)->toBe($org->id);
});
