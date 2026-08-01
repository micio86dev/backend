<?php

declare(strict_types=1);

/**
 * RBAC on the admin read endpoints (C11 PR A3, task 9.4).
 *
 * Spec: "List and detail return only RBAC-gated data" — all three Spatie
 * roles (admin/operator/viewer) may read; ParticipantPolicy/EvaluationPolicy
 * (D3) apply no owner filter. This is a materially different concern from
 * the BEAI organizational roles (ICO/FLL/MLL/BUL/SRX) — Spatie roles here
 * are pure authorization, never confused with the domain roles.
 *
 * REQ: Admin Read Endpoint Surface (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md)
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function rbacReadUser(Organization $org, ?string $role): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);

    if ($role !== null) {
        $spatieRole = SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
        $user->assignRole($spatieRole);
    }

    return auth('api')->login($user);
}

function rbacReadParticipant(Organization $org): Participant
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);

    return Participant::factory()->forProject($project)->withStatus('completato')->create();
}

test('viewer, operator, and admin can all read the list, detail, and dashboard', function (string $role): void {
    $org = Organization::factory()->create();
    $token = rbacReadUser($org, $role);
    $participant = rbacReadParticipant($org);

    $this->withToken($token)->getJson('/api/participants')->assertOk();
    $this->withToken($token)->getJson("/api/participants/{$participant->id}")->assertOk();
    $this->withToken($token)->getJson('/api/dashboard/metrics')->assertOk();
})->with(['viewer', 'operator', 'admin']);

test('a user with no Spatie role at all is denied (403), never a leaked read', function (): void {
    $org = Organization::factory()->create();
    $token = rbacReadUser($org, null);
    $participant = rbacReadParticipant($org);

    $this->withToken($token)->getJson('/api/participants')->assertForbidden();
    $this->withToken($token)->getJson("/api/participants/{$participant->id}")->assertForbidden();
});
