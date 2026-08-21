<?php

declare(strict_types=1);

/**
 * RED — CompetencyTally (operator-participant-visibility D5, task 2.1).
 *
 * `CompetencyTally::ended()`/`::total()` is the MOVED (not re-implemented)
 * `InterviewController::endedCompetencyCount()` predicate plus its
 * denominator, so admin and candidate surfaces can never disagree about
 * "how many competencies has this participant ended". The predicate:
 * `completed|timeout|skipped` count unconditionally; `error` counts ONLY
 * once it has spent its re-offer (`error_count >= InterviewSession::
 * MAX_ERROR_ATTEMPTS`) — a `pending`/`in_corso` session, and a not-yet-spent
 * `error` session, never count.
 *
 * REQ: Participant Detail Summary Fields
 *      (openspec/changes/operator-participant-visibility/specs/admin-read-api/spec.md)
 */

use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Interview\CompetencyTally;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;

function tallyOrg(): Organization
{
    return Organization::factory()->create();
}

/**
 * @return array{Project, list<Competency>}
 */
function tallyProjectWithCompetencies(Organization $org, int $count): array
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active']);
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

function tallyParticipant(Organization $org, Project $project, string $status = 'in_corso'): Participant
{
    return Participant::factory()->forProject($project)->withStatus($status)->create();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tallySession(Organization $org, Participant $participant, Project $project, string $code, array $overrides = []): InterviewSession
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $session = InterviewSession::create(array_merge([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'fake',
        'provider_session_ref' => 'ref-'.uniqid(),
        'status' => 'completed',
    ], $overrides));

    if (array_key_exists('error_count', $overrides)) {
        DB::table('interview_sessions')
            ->where('id', $session->id)
            ->update(['error_count' => $overrides['error_count']]);
        $session->refresh();
    }

    return $session;
}

test('ended() counts completed, timeout and skipped sessions unconditionally', function (): void {
    $org = tallyOrg();
    [$project, $comps] = tallyProjectWithCompetencies($org, 3);
    $participant = tallyParticipant($org, $project);

    tallySession($org, $participant, $project, $comps[0]->code, ['status' => 'completed']);
    tallySession($org, $participant, $project, $comps[1]->code, ['status' => 'timeout']);
    tallySession($org, $participant, $project, $comps[2]->code, ['status' => 'skipped']);

    expect((new CompetencyTally)->ended($participant->id, $project->id))->toBe(3);
});

test('ended() excludes pending and in_corso sessions', function (): void {
    $org = tallyOrg();
    [$project, $comps] = tallyProjectWithCompetencies($org, 2);
    $participant = tallyParticipant($org, $project);

    tallySession($org, $participant, $project, $comps[0]->code, ['status' => 'pending']);
    tallySession($org, $participant, $project, $comps[1]->code, ['status' => 'in_corso']);

    expect((new CompetencyTally)->ended($participant->id, $project->id))->toBe(0);
});

test('ended() excludes an error session that has not yet spent its re-offer', function (): void {
    $org = tallyOrg();
    [$project, $comps] = tallyProjectWithCompetencies($org, 1);
    $participant = tallyParticipant($org, $project);

    // MAX_ERROR_ATTEMPTS is 2 — error_count 1 is still offerable.
    tallySession($org, $participant, $project, $comps[0]->code, [
        'status' => 'error',
        'error_count' => 1,
    ]);

    expect((new CompetencyTally)->ended($participant->id, $project->id))->toBe(0);
});

test('ended() counts a spent-retry error session (error_count >= MAX_ERROR_ATTEMPTS)', function (): void {
    $org = tallyOrg();
    [$project, $comps] = tallyProjectWithCompetencies($org, 1);
    $participant = tallyParticipant($org, $project);

    expect(InterviewSession::MAX_ERROR_ATTEMPTS)->toBe(2);

    tallySession($org, $participant, $project, $comps[0]->code, [
        'status' => 'error',
        'error_count' => 2,
    ]);

    expect((new CompetencyTally)->ended($participant->id, $project->id))->toBe(1);
});

test('ended() only counts sessions for the given participant and project', function (): void {
    $org = tallyOrg();
    [$projectA, $compsA] = tallyProjectWithCompetencies($org, 1);
    [$projectB, $compsB] = tallyProjectWithCompetencies($org, 1);
    $participantA = tallyParticipant($org, $projectA);
    $participantB = tallyParticipant($org, $projectB);

    tallySession($org, $participantA, $projectA, $compsA[0]->code, ['status' => 'completed']);
    tallySession($org, $participantB, $projectB, $compsB[0]->code, ['status' => 'completed']);

    expect((new CompetencyTally)->ended($participantA->id, $projectA->id))->toBe(1);
});

test('total() equals count(project_competencies) for the project', function (): void {
    $org = tallyOrg();
    [$project] = tallyProjectWithCompetencies($org, 15);

    expect((new CompetencyTally)->total($project->id))->toBe(15);
});

test('total() is zero for a project with no configured competencies', function (): void {
    $org = tallyOrg();
    [$project] = tallyProjectWithCompetencies($org, 0);

    expect((new CompetencyTally)->total($project->id))->toBe(0);
});

// ── The exact case a naive re-implementation would get wrong (design D5) ──

test('a spent-retry error session mixed with normally-ended sessions matches the full fixture', function (): void {
    $org = tallyOrg();
    [$project, $comps] = tallyProjectWithCompetencies($org, 4);
    $participant = tallyParticipant($org, $project);

    tallySession($org, $participant, $project, $comps[0]->code, ['status' => 'completed']);
    tallySession($org, $participant, $project, $comps[1]->code, ['status' => 'skipped']);
    tallySession($org, $participant, $project, $comps[2]->code, [
        'status' => 'error',
        'error_count' => 2, // spent-retry — counts as ended
    ]);
    tallySession($org, $participant, $project, $comps[3]->code, ['status' => 'in_corso']); // still open

    $tally = new CompetencyTally;

    expect($tally->ended($participant->id, $project->id))->toBe(3)
        ->and($tally->total($project->id))->toBe(4);
});
