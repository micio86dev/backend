<?php

declare(strict_types=1);

/**
 * RED — `GET /api/participants/{id}` carries the five missing facts
 * (operator-participant-visibility D3/D4/D6, tasks 2.17-2.19).
 *
 * `status` stays a literal domain value — never a boolean/reduction — and
 * `progress`/`elapsed`/`cost` are shaped exactly per the design's Interfaces
 * contract, sourced from `ParticipantInterviewAggregator` (called once).
 *
 * REQ: Participant Detail Summary Fields
 *      (openspec/changes/operator-participant-visibility/specs/admin-read-api/spec.md)
 */

use App\Models\Competency;
use App\Models\FrameworkVersion;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function detailAggAdminUser(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return auth('api')->login($user);
}

/**
 * @return array{Project, list<Competency>}
 */
function detailAggProjectWithCompetencies(Organization $org, int $count): array
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $competencies = [];

    for ($i = 0; $i < $count; $i++) {
        $comp = Competency::factory()->create();
        DB::table('project_competencies')->insert([
            'project_id' => $project->id,
            'competency_id' => $comp->id,
            'position' => $i + 1,
        ]);
        $competencies[] = $comp;
    }

    return [$project, $competencies];
}

function detailAggParticipant(Project $project, string $status): Participant
{
    return Participant::factory()->forProject($project)->withStatus($status)->create();
}

// ── status is a literal domain value ────────────────────────────────────

test('detail status is the real literal value, never a boolean/reduction', function (string $status): void {
    $org = Organization::factory()->create();
    $token = detailAggAdminUser($org);
    [$project] = detailAggProjectWithCompetencies($org, 1);
    $participant = detailAggParticipant($project, $status);

    $response = $this->withToken($token)->getJson("/api/participants/{$participant->id}");

    $response->assertOk();
    expect($response->json('data.status'))->toBe($status);
})->with([
    'in_attesa', 'in_corso', 'in_valutazione', 'completato', 'errore',
]);

// ── progress/elapsed/cost shape ─────────────────────────────────────────

test('detail carries progress, elapsed and cost shaped per the Interfaces contract', function (): void {
    $org = Organization::factory()->create();
    $token = detailAggAdminUser($org);
    [$project, $comps] = detailAggProjectWithCompetencies($org, 2);
    $participant = detailAggParticipant($project, 'in_corso');

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $comps[0]->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'provider_session_ref' => 'ref-0',
        'status' => 'completed',
        'started_at' => '2026-03-01 10:00:00',
        'ended_at' => '2026-03-01 10:05:00',
    ]);

    $response = $this->withToken($token)->getJson("/api/participants/{$participant->id}");

    $response->assertOk();
    expect($response->json('data.progress'))->toBe(['done' => 1, 'total' => 2]);
    expect($response->json('data.elapsed.seconds'))->toBe(300);
    expect($response->json('data.elapsed.sessions_counted'))->toBe(1);
    expect($response->json('data.elapsed.sessions_total'))->toBe(1);
    expect($response->json('data.cost.currency'))->toBe('USD');
    expect($response->json('data.cost.is_estimate'))->toBeTrue();
    expect($response->json('data.cost.sessions_total'))->toBe(1);
});

test('detail elapsed/cost are absent (null), never zero, when nothing has finished', function (): void {
    $org = Organization::factory()->create();
    $token = detailAggAdminUser($org);
    [$project, $comps] = detailAggProjectWithCompetencies($org, 1);
    $participant = detailAggParticipant($project, 'in_corso');

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $comps[0]->code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'heygen',
        'provider_session_ref' => 'ref-0',
        'status' => 'in_corso',
        'started_at' => now(),
        'ended_at' => null,
    ]);

    $response = $this->withToken($token)->getJson("/api/participants/{$participant->id}");

    $response->assertOk();
    // Structure asserted explicitly: a wholly ABSENT `elapsed`/`cost` block
    // would also report `seconds`/`amount` as null via dot-notation lookup,
    // which would make this assertion pass for the wrong reason. The keys
    // must exist AND their value must be null.
    $response->assertJsonStructure([
        'data' => [
            'elapsed' => ['seconds', 'sessions_counted', 'sessions_total'],
            'cost' => ['amount', 'currency', 'is_estimate', 'sessions_estimated', 'sessions_total'],
        ],
    ]);
    expect($response->json('data.elapsed.seconds'))->toBeNull();
    expect($response->json('data.elapsed.sessions_counted'))->toBe(0);
    expect($response->json('data.cost.amount'))->toBeNull();
    expect($response->json('data.cost.sessions_estimated'))->toBe(0);
});
