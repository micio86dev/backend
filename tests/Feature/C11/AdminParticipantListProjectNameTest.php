<?php

declare(strict_types=1);

/**
 * RED — `GET /api/participants` list row carries `project_name`
 * (operator-participant-visibility D6, tasks 2.20-2.21).
 *
 * `project_name` is resolved via `AdminParticipantReader::listQuery()`'s
 * eager load (`->with('project:id,name')`) — in the reader, not the
 * controller, so a future list caller cannot forget it and silently
 * lazy-load per row. An orphaned FK renders `null`, not a thrown error
 * (unlike `ParticipantDetailResource`'s `firstOrFail()`): one bad row must
 * not blank the whole list page.
 *
 * REQ: Participants List Carries Project Identity
 *      (openspec/changes/operator-participant-visibility/specs/admin-read-api/spec.md)
 */

use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function listProjectNameAdminUser(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return auth('api')->login($user);
}

function listProjectNameProjectIn(Organization $org, string $name): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);

    return Project::factory()->create(['framework_version_id' => $fv->id, 'name' => $name]);
}

test('GET /api/participants — every row carries its own project name', function (): void {
    $org = Organization::factory()->create();
    $token = listProjectNameAdminUser($org);

    $projectA = listProjectNameProjectIn($org, 'Project Alpha');
    $projectB = listProjectNameProjectIn($org, 'Project Beta');

    $participantA = Participant::factory()->forProject($projectA)->create();
    $participantB = Participant::factory()->forProject($projectB)->create();

    $response = $this->withToken($token)->getJson('/api/participants');

    $response->assertOk();
    $rows = collect($response->json('data'))->keyBy('id');

    expect($rows[$participantA->id]['project_name'])->toBe('Project Alpha');
    expect($rows[$participantB->id]['project_name'])->toBe('Project Beta');
});

test('GET /api/participants — an orphaned project FK renders project_name null, not a thrown error', function (): void {
    $org = Organization::factory()->create();
    $token = listProjectNameAdminUser($org);

    $project = listProjectNameProjectIn($org, 'Doomed Project');
    $participant = Participant::factory()->forProject($project)->create();

    // `participants.project_id` is FK-constrained with cascadeOnDelete — a
    // normal delete of the project would cascade-delete the participant too,
    // not orphan it. Disable the trigger that enforces the cascade (mirrors
    // ScoreEvaluationJobTenancyTest.php's scoreTenancyUnresolvableOrgParticipant()
    // pattern) to simulate the should-never-happen corrupted-data state the
    // list must still render without throwing.
    DB::statement('ALTER TABLE projects DISABLE TRIGGER ALL');

    try {
        DB::table('projects')->where('id', $project->id)->delete();
    } finally {
        DB::statement('ALTER TABLE projects ENABLE TRIGGER ALL');
    }

    $response = $this->withToken($token)->getJson('/api/participants');

    $response->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $participant->id);

    expect($row)->not->toBeNull();
    expect($row['project_name'])->toBeNull();
});

test('GET /api/participants — list query count is invariant to row count (N+1 guard)', function (): void {
    $org = Organization::factory()->create();
    $token = listProjectNameAdminUser($org);

    $projectOne = listProjectNameProjectIn($org, 'Solo Project');
    Participant::factory()->forProject($projectOne)->create();

    // Warm-up call: Spatie's permission/role lookups are cached PER PROCESS
    // after the first authenticated request, so comparing an uncached first
    // call against a cached second call would report a false N+1 (fewer
    // queries on the SECOND call, for reasons unrelated to row count). Both
    // measured calls below run with a warm cache, isolating the comparison
    // to the participants/projects queries this guard actually cares about.
    $this->withToken($token)->getJson('/api/participants')->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->withToken($token)->getJson('/api/participants')->assertOk();
    $oneRowQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    $projectTwo = listProjectNameProjectIn($org, 'Second Project');
    $projectThree = listProjectNameProjectIn($org, 'Third Project');
    Participant::factory()->forProject($projectTwo)->create();
    Participant::factory()->forProject($projectThree)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->withToken($token)->getJson('/api/participants')->assertOk();
    $threeRowQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($threeRowQueryCount)->toBe($oneRowQueryCount, 'Query count must not grow with row count — a per-row project lookup would be an N+1.');
});
