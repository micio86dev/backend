<?php

declare(strict_types=1);

/**
 * Dashboard recent-activity feed (C11 — Admin Dashboards, DESIGN.md §8.2).
 *
 * The dashboard shipped as four counters and an empty page: it said how much
 * work existed but never what had just happened, so an operator had to open
 * the candidate list to learn whether anything was moving.
 *
 * Asserts:
 * - admin GET → 200, most-recently-updated participants first
 * - org-scoped: another tenant's candidates never appear
 * - capped, so the feed cannot grow into a second candidate list
 * - carries the project name, so a row is readable without a second lookup
 * - operator may read it (it is the same data as the candidate list they can
 *   already see); viewer is refused by the same policy as that list
 *
 * REQ: Admin dashboards
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function activityUser(Organization $org, string $role = 'admin'): string
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $spatieRole = SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($spatieRole);

    return (string) auth('api')->login($user);
}

/**
 * `Project::factory()` pulls in a tenant-scoped FrameworkVersion, which refuses
 * to be created without an ambient tenant — same helper shape as
 * AdminDownloadTest and AdminLifecycleGateMatrixTest.
 */
function activityProject(Organization $org, string $name = 'Demo'): Project
{
    // Tenant context has to be ambient BEFORE creating a tenant-scoped model —
    // same setup as AdminDownloadTest::downloadTestProjectIn.
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    return Project::factory()->create([
        'organization_id' => $org->id,
        'framework_version_id' => $fv->id,
        'name' => $name,
    ]);
}

function activityParticipant(Project $project, string $ref, string $status, string $updatedAt): Participant
{
    $participant = Participant::factory()->create([
        'organization_id' => $project->organization_id,
        'project_id' => $project->id,
        'candidate_ref' => $ref,
        'display_name' => 'Name '.$ref,
        'status' => $status,
    ]);

    // `updated_at` drives the ordering, so it is set explicitly rather than
    // left to whatever order the factory happened to insert in.
    $participant->forceFill(['updated_at' => $updatedAt])->saveQuietly();

    return $participant;
}

test('admin GET /api/dashboard/activity returns the most recent first', function (): void {
    $org = Organization::factory()->create();
    $token = activityUser($org);
    $project = activityProject($org, 'Alpha');

    activityParticipant($project, 'oldest', 'in_attesa', '2026-01-01 10:00:00');
    activityParticipant($project, 'newest', 'completato', '2026-03-01 10:00:00');
    activityParticipant($project, 'middle', 'in_corso', '2026-02-01 10:00:00');

    $response = $this->withToken($token)->getJson('/api/dashboard/activity');

    $response->assertOk()
        ->assertJsonStructure(['data' => [['candidate_ref', 'display_name', 'status', 'project_name', 'updated_at']]]);

    expect(array_column($response->json('data'), 'candidate_ref'))
        ->toBe(['newest', 'middle', 'oldest']);
});

test('a row carries the project name, so it reads without a second lookup', function (): void {
    $org = Organization::factory()->create();
    $token = activityUser($org);
    $project = activityProject($org, 'Retail Managers');
    activityParticipant($project, 'one', 'in_corso', '2026-03-01 10:00:00');

    $response = $this->withToken($token)->getJson('/api/dashboard/activity');

    expect($response->json('data.0.project_name'))->toBe('Retail Managers');
});

test('another tenant\'s candidates never appear', function (): void {
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    $token = activityUser($mine);

    $myProject = activityProject($mine);
    $theirProject = activityProject($theirs);

    activityParticipant($myProject, 'mine', 'in_corso', '2026-01-01 10:00:00');
    activityParticipant($theirProject, 'theirs', 'in_corso', '2026-03-01 10:00:00');

    $response = $this->withToken($token)->getJson('/api/dashboard/activity');

    expect(array_column($response->json('data'), 'candidate_ref'))->toBe(['mine']);
});

test('the feed is capped so it cannot become a second candidate list', function (): void {
    $org = Organization::factory()->create();
    $token = activityUser($org);
    $project = activityProject($org);

    for ($i = 0; $i < 25; $i++) {
        activityParticipant($project, "ref-{$i}", 'in_corso', '2026-01-01 10:00:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
    }

    $response = $this->withToken($token)->getJson('/api/dashboard/activity');

    expect(count($response->json('data')))->toBeLessThanOrEqual(10);
});

test('an empty organization returns an empty feed, not an error', function (): void {
    $org = Organization::factory()->create();
    $token = activityUser($org);

    $this->withToken($token)->getJson('/api/dashboard/activity')
        ->assertOk()
        ->assertJson(['data' => []]);
});

test('an operator may read the feed', function (): void {
    $org = Organization::factory()->create();
    $token = activityUser($org, 'operator');

    $this->withToken($token)->getJson('/api/dashboard/activity')->assertOk();
});

test('unauthenticated GET /api/dashboard/activity → 401', function (): void {
    $this->getJson('/api/dashboard/activity')->assertUnauthorized();
});
