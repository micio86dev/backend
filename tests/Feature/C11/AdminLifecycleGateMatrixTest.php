<?php

declare(strict_types=1);

/**
 * Lifecycle read-gate matrix on transcript/evaluation read + download
 * (C11 PR A3, tasks 9.2, 9.3, D2/D4; loosened for operator-participant-visibility
 * PR1, D1/D3).
 *
 * Table (operator-participant-visibility spec.md:24-31):
 *
 * | status          | transcript | evaluation |
 * |-----------------|------------|------------|
 * | in_attesa       | 409        | 409        |
 * | in_corso        | 200        | 409        |
 * | in_valutazione  | 200        | 409        |
 * | completato      | 200        | 200        |
 * | errore          | 200        | 409        |
 * | unrecognized    | 409        | 409        |
 *
 * 403 MUST NEVER appear for a gate denial — that status is reserved for
 * RBAC (AdminRbacReadTest.php), a materially different failure the
 * backoffice must be able to distinguish (D4).
 *
 * REQ: Lifecycle Read-Gate (Fail-Closed)
 *      (openspec/changes/operator-participant-visibility/specs/admin-read-api/spec.md)
 */

use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function gateMatrixToken(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $role = SpatieRole::firstOrCreate(['name' => 'operator', 'guard_name' => 'api', 'team_id' => $org->id]);
    $user->assignRole($role);

    return auth('api')->login($user);
}

function gateMatrixParticipant(Organization $org, string $status): Participant
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);

    return Participant::factory()->forProject($project)->withStatus($status)->create();
}

test('transcript read + download gate matrix', function (string $status, int $expected): void {
    $org = Organization::factory()->create();
    $token = gateMatrixToken($org);
    $participant = gateMatrixParticipant($org, $status);

    $read = $this->withToken($token)->getJson("/api/participants/{$participant->id}/transcript");
    $download = $this->withToken($token)->getJson("/api/participants/{$participant->id}/transcript/download");

    $read->assertStatus($expected);
    $download->assertStatus($expected);
    expect($read->status())->not->toBe(403);
    expect($download->status())->not->toBe(403);

    if ($expected === 409) {
        $read->assertJsonPath('error', 'lifecycle_not_ready');
        $read->assertJsonPath('resource', 'transcript');
        // D1: the Transcript minimum moved from in_valutazione to in_corso —
        // the only remaining 409 row (in_attesa) must report the NEW threshold.
        $read->assertJsonPath('required_status', 'in_corso');
    }
})->with([
    'in_attesa' => ['in_attesa', 409],
    'in_corso' => ['in_corso', 200],
    'in_valutazione' => ['in_valutazione', 200],
    'completato' => ['completato', 200],
    'errore' => ['errore', 200],
]);

test('evaluation read + download gate matrix', function (string $status, int $expected): void {
    $org = Organization::factory()->create();
    $token = gateMatrixToken($org);
    $participant = gateMatrixParticipant($org, $status);

    // An Evaluation row only exists in reality once scoring has run — create
    // one here so the 200 (completato) case exercises the REAL serializer
    // path, not an incidental ModelNotFoundException. Harmless for the
    // gated cases: LifecycleReadGate::assert() throws before
    // AdminEvaluationSerializer ever touches this row.
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);
    $result = CompetencyResult::factory()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'COL',
        'score' => 4.0,
        'reliability' => 0.9,
    ]);
    IndicatorScore::factory()->create(['competency_result_id' => $result->id, 'position' => 0]);

    $read = $this->withToken($token)->getJson("/api/participants/{$participant->id}/evaluation");
    $download = $this->withToken($token)->getJson("/api/participants/{$participant->id}/evaluation/download");

    $read->assertStatus($expected);
    $download->assertStatus($expected);
    expect($read->status())->not->toBe(403);
    expect($download->status())->not->toBe(403);

    if ($expected === 409) {
        $read->assertJsonPath('error', 'lifecycle_not_ready');
        $read->assertJsonPath('resource', 'evaluation');
        $read->assertJsonPath('required_status', 'completato');
    }
})->with([
    'in_attesa' => ['in_attesa', 409],
    'in_corso' => ['in_corso', 409],
    'in_valutazione' => ['in_valutazione', 409],
    'completato' => ['completato', 200],
    'errore' => ['errore', 409],
]);

test('an unrecognized status denies both gated scopes, fail-closed, never 200', function (): void {
    $org = Organization::factory()->create();
    $token = gateMatrixToken($org);
    $participant = gateMatrixParticipant($org, 'completato');

    // Force a status value outside the known lifecycle enum, bypassing the
    // model's transition guard (direct DB write — simulates data corruption
    // or a future/foreign status value, exactly what fail-closed defends
    // against).
    DB::table('participants')
        ->where('id', $participant->id)
        ->update(['status' => 'totally_unknown_status']);

    $transcript = $this->withToken($token)->getJson("/api/participants/{$participant->id}/transcript");
    $evaluation = $this->withToken($token)->getJson("/api/participants/{$participant->id}/evaluation");

    $transcript->assertStatus(409)->assertJsonPath('error', 'lifecycle_not_ready');
    $evaluation->assertStatus(409)->assertJsonPath('error', 'lifecycle_not_ready');
});

test('list and detail (Summary scope) return 200 regardless of lifecycle status, including errore', function (string $status): void {
    $org = Organization::factory()->create();
    $token = gateMatrixToken($org);
    $participant = gateMatrixParticipant($org, $status);

    $this->withToken($token)->getJson("/api/participants/{$participant->id}")->assertOk();
    $this->withToken($token)->getJson('/api/participants')->assertOk();
})->with(['in_attesa', 'in_corso', 'in_valutazione', 'completato', 'errore']);
