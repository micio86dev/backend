<?php

declare(strict_types=1);

/**
 * RED — Admin participant list endpoint (C11 PR A3, task 8.1).
 *
 * GET /api/participants: server-paginated, filters project_id/status/q,
 * per_page clamped [1,100] default 20, sort fixed created_at desc, id desc
 * (D5 — no client-specified sort). Tenant-scoped via
 * AdminParticipantReader::listQuery() — never a bare Participant:: call in
 * the controller (arch-tested, AdminTenancySafetyArchTest task 2.3b).
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

function indexTestAdminUser(Organization $org, string $role = 'admin'): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $spatieRole = SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return auth('api')->login($user);
}

function indexTestProjectIn(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    return Project::factory()->create(['framework_version_id' => $fv->id]);
}

function indexTestParticipant(Project $project, array $overrides = []): Participant
{
    return Participant::factory()->forProject($project)->create($overrides);
}

test('GET /api/participants lists only own-org participants, newest first', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $token = indexTestAdminUser($orgA);

    $projectA = indexTestProjectIn($orgA);
    $older = indexTestParticipant($projectA, ['created_at' => now()->subDay()]);
    $newer = indexTestParticipant($projectA, ['created_at' => now()]);

    $projectB = indexTestProjectIn($orgB);
    indexTestParticipant($projectB);

    $response = $this->withToken($token)->getJson('/api/participants');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toHaveCount(2);
    expect($ids->first())->toBe($newer->id);
    expect($ids->last())->toBe($older->id);
});

test('GET /api/participants filters by project_id, status, and q', function (): void {
    $org = Organization::factory()->create();
    $token = indexTestAdminUser($org);

    $projectOne = indexTestProjectIn($org);
    $projectTwo = indexTestProjectIn($org);

    $match = indexTestParticipant($projectOne, [
        'status' => 'completato',
        'candidate_ref' => 'ref-alpha-001',
        'display_name' => 'Alpha Candidate',
    ]);
    indexTestParticipant($projectOne, ['status' => 'in_corso', 'candidate_ref' => 'ref-beta-002']);
    indexTestParticipant($projectTwo, ['status' => 'completato', 'candidate_ref' => 'ref-gamma-003']);

    $response = $this->withToken($token)->getJson(
        '/api/participants?project_id='.$projectOne->id.'&status=completato&q=alpha'
    );

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toEqual(collect([$match->id]));
});

test('GET /api/participants clamps per_page into [1,100] and defaults to 20', function (): void {
    $org = Organization::factory()->create();
    $token = indexTestAdminUser($org);
    $project = indexTestProjectIn($org);

    for ($i = 0; $i < 3; $i++) {
        indexTestParticipant($project);
    }

    $default = $this->withToken($token)->getJson('/api/participants');
    $default->assertOk()->assertJsonPath('meta.per_page', 20);

    $clampedHigh = $this->withToken($token)->getJson('/api/participants?per_page=500');
    $clampedHigh->assertOk()->assertJsonPath('meta.per_page', 100);

    $clampedLow = $this->withToken($token)->getJson('/api/participants?per_page=0');
    $clampedLow->assertOk()->assertJsonPath('meta.per_page', 1);
});
