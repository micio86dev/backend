<?php

declare(strict_types=1);

/**
 * The backfill migration recomputes `question_index` from `position`; it never
 * shifts (interview-question-index-offset, D2/D3/D4) — THE CRUX of this change.
 *
 * Production is mixed: 29 already-correct rows (`DemoWriter.php:404`, which never
 * subtracted) alongside 8 drifted rows at three distinct shapes (`-1`, `(0,1)`,
 * `(1,2)`). A blanket `+1` shift would corrupt the 29; only a join back to
 * `project_competencies.position` tells a correct row from a drifted one — two of
 * the eight drifted rows are indistinguishable from correct rows by their own value
 * alone.
 *
 * These tests `require` the REAL migration file and call `->up()` directly — never
 * a copied SQL string — so the mandatory mutation check (task 2.3: temporarily
 * swap the migration's statement for a blanket `+1` shift, then for an Eloquent
 * mass update) is observed against the SAME tests that gate the PR, not a stand-in
 * that would keep passing after the real migration regressed.
 */

use App\Models\Competency;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/** Load the real migration file and return a fresh instance — never a copied SQL string. */
function qioMigration(): object
{
    return require database_path('migrations/2026_08_21_160000_recompute_interview_session_question_index.php');
}

function qioRunMigration(): void
{
    qioMigration()->up();
}

/**
 * An organization + a project with `$competencyCount` competencies at 0-based
 * `position` + one participant, all in a fresh tenant context.
 *
 * @return array{0: Organization, 1: Project, 2: Participant, 3: list<Competency>}
 */
function qioOrgProjectParticipant(int $competencyCount): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active']);

    $competencies = [];
    for ($i = 0; $i < $competencyCount; $i++) {
        $comp = Competency::factory()->create();
        DB::table('project_competencies')->insert([
            'project_id' => $project->id,
            'competency_id' => $comp->id,
            'position' => $i,
        ]);
        $competencies[] = $comp;
    }

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'qio-mig-'.uniqid(),
        'display_name' => 'QIO Migration Candidate',
        'email' => uniqid('cand-').'@example.test',
        'status' => 'in_valutazione',
    ]);
    $participant->save();

    return [$org, $project, $participant->fresh(), $competencies];
}

/** A session row written straight to the table — bypassing tenant scope and Eloquent, as production rows already exist. */
function qioSession(
    int $orgId,
    int $projectId,
    int $participantId,
    string $competencyCode,
    int $questionIndex,
    ?DateTimeInterface $timestamp = null,
): int {
    $ts = $timestamp ?? now();

    return DB::table('interview_sessions')->insertGetId([
        'organization_id' => $orgId,
        'participant_id' => $participantId,
        'project_id' => $projectId,
        'question_index' => $questionIndex,
        'competency_code' => $competencyCode,
        'framework_version_id' => DB::table('framework_versions')->where('organization_id', $orgId)->value('id'),
        'provider' => 'heygen',
        'provider_session_ref' => null,
        'status' => 'completed',
        'ended_reason' => 'completed',
        'started_at' => $ts,
        'ended_at' => $ts,
        'created_at' => $ts,
        'updated_at' => $ts,
    ]);
}

/** The full raw row, as an array, for byte-identical comparison. */
function qioRawRow(int $id): array
{
    return (array) DB::table('interview_sessions')->where('id', $id)->first();
}

test('already-correct rows are byte-identical after the migration; drifted rows recompute — across two tenants', function (): void {
    // Org A: DemoWriter shape — 5 competencies, dense 0..4, already correct.
    [$orgA, $projectA, $participantA, $compsA] = qioOrgProjectParticipant(5);
    $fixedTs = now()->subDays(3);
    $correctIds = [];
    foreach ($compsA as $i => $comp) {
        $correctIds[] = qioSession($orgA->id, $projectA->id, $participantA->id, $comp->code, $i, $fixedTs);
    }

    // Org B: the three drifted shapes from the production join — -1/0, 0/1, 1/2.
    [$orgB, $projectB, $participantB, $compsB] = qioOrgProjectParticipant(3);
    $negativeOne = qioSession($orgB->id, $projectB->id, $participantB->id, $compsB[0]->code, -1, $fixedTs);
    $zeroOne = qioSession($orgB->id, $projectB->id, $participantB->id, $compsB[1]->code, 0, $fixedTs);
    $oneTwo = qioSession($orgB->id, $projectB->id, $participantB->id, $compsB[2]->code, 1, $fixedTs);

    $before = collect($correctIds)->mapWithKeys(fn (int $id) => [$id => qioRawRow($id)]);

    qioRunMigration();

    foreach ($correctIds as $id) {
        expect(qioRawRow($id))->toBe($before[$id], "row {$id} (already correct) must be byte-identical, updated_at included");
    }

    expect(DB::table('interview_sessions')->where('id', $negativeOne)->value('question_index'))
        ->toBe(0, 'the -1/position-0 row recomputes to its competency\'s current position');
    expect(DB::table('interview_sessions')->where('id', $zeroOne)->value('question_index'))
        ->toBe(1, 'the 0/position-1 row recomputes to its competency\'s current position');
    expect(DB::table('interview_sessions')->where('id', $oneTwo)->value('question_index'))
        ->toBe(2, 'the 1/position-2 row recomputes to its competency\'s current position');
});

test('running the migration a second time changes nothing (whole-row diff)', function (): void {
    [$org, $project, $participant, $comps] = qioOrgProjectParticipant(3);
    $ids = [
        qioSession($org->id, $project->id, $participant->id, $comps[0]->code, -1),
        qioSession($org->id, $project->id, $participant->id, $comps[1]->code, 5), // arbitrary wrong value
        qioSession($org->id, $project->id, $participant->id, $comps[2]->code, 2), // already correct
    ];

    qioRunMigration();
    $afterFirstRun = collect($ids)->mapWithKeys(fn (int $id) => [$id => qioRawRow($id)]);

    qioRunMigration();
    $afterSecondRun = collect($ids)->mapWithKeys(fn (int $id) => [$id => qioRawRow($id)]);

    expect($afterSecondRun->all())->toBe($afterFirstRun->all(), 'a second run must be a no-op on every column of every row');
});

test('drifted rows are corrected in BOTH of two distinct organizations', function (): void {
    [$orgA, $projectA, $participantA, $compsA] = qioOrgProjectParticipant(1);
    [$orgB, $projectB, $participantB, $compsB] = qioOrgProjectParticipant(1);

    $idA = qioSession($orgA->id, $projectA->id, $participantA->id, $compsA[0]->code, -1);
    $idB = qioSession($orgB->id, $projectB->id, $participantB->id, $compsB[0]->code, -1);

    qioRunMigration();

    expect(DB::table('interview_sessions')->where('id', $idA)->value('question_index'))
        ->toBe(0, 'org A\'s drifted row must be corrected — no ambient TenantContext scoping should silently skip it');
    expect(DB::table('interview_sessions')->where('id', $idB)->value('question_index'))
        ->toBe(0, 'org B\'s drifted row must be corrected — no ambient TenantContext scoping should silently skip it');
});

test('a session whose competency was sync()-detached is left untouched and reported by id', function (): void {
    [$org, $project, $participant, $comps] = qioOrgProjectParticipant(1);
    $sessionId = qioSession($org->id, $project->id, $participant->id, $comps[0]->code, -1);

    // Simulate ProjectController::update()'s detaching sync() (D3): the pivot row
    // for this competency no longer exists, so the join has nothing to match.
    DB::table('project_competencies')
        ->where('project_id', $project->id)
        ->where('competency_id', $comps[0]->id)
        ->delete();

    Log::spy();

    qioRunMigration();

    expect(DB::table('interview_sessions')->where('id', $sessionId)->value('question_index'))
        ->toBe(-1, 'a detached competency\'s session must be left untouched — never written as 0 or NULL');

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($sessionId): bool {
        return str_contains($message, 'detached')
            && in_array($sessionId, $context['session_ids'] ?? [], true);
    });
});
