<?php

declare(strict_types=1);

/**
 * CompetencyProgress (live-interview-call-chrome, T-A1.1).
 *
 * One owner of the competency order and of "which competency is open", moved out of
 * `InterviewController` so `/start` and the interview outline can never disagree about
 * which competency is current. `resolveNext()` is the MOVED `resolveNextCompetency()`:
 * its behaviour is pinned here, and the four existing `/start` suites are the
 * regression net for the call site.
 *
 * REQ: interview-session "Interview outline" (openspec/changes/live-interview-call-chrome)
 */

use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Interview\CompetencyProgress;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;

function cprOrg(): Organization
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return $org;
}

/**
 * @param  list<Competency>|null  $competencies  reused so two projects can share codes
 * @return array{Project, list<Competency>}
 */
function cprProject(int $count, ?array $competencies = null): array
{
    $project = Project::factory()->create(['status' => 'active']);
    $competencies ??= array_map(fn () => Competency::factory()->create(), range(1, $count));

    foreach ($competencies as $position => $competency) {
        DB::table('project_competencies')->insert([
            'project_id' => $project->id,
            'competency_id' => $competency->id,
            'position' => $position,
        ]);
    }

    return [$project, $competencies];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function cprSession(Participant $participant, Project $project, string $code, array $overrides = []): InterviewSession
{
    $session = InterviewSession::create([
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'question_index' => 0,
        'competency_code' => $code,
        'framework_version_id' => $project->framework_version_id,
        'provider' => 'fake',
        'provider_session_ref' => 'ref-'.uniqid(),
        'status' => $overrides['status'] ?? 'pending',
    ]);

    if (array_key_exists('error_count', $overrides)) {
        DB::table('interview_sessions')->where('id', $session->id)->update(['error_count' => $overrides['error_count']]);
        $session->refresh();
    }

    return $session;
}

test('isOpen is true for a missing session and for pending and in_corso sessions', function (): void {
    cprOrg();
    [$project, $comps] = cprProject(2);
    $participant = Participant::factory()->forProject($project)->withStatus('in_corso')->create();

    expect(CompetencyProgress::isOpen(null))->toBeTrue()
        ->and(CompetencyProgress::isOpen(cprSession($participant, $project, $comps[0]->code, ['status' => 'pending'])))->toBeTrue()
        ->and(CompetencyProgress::isOpen(cprSession($participant, $project, $comps[1]->code, ['status' => 'in_corso'])))->toBeTrue();
});

test('isOpen treats an errored session as open only while attempts are left', function (): void {
    cprOrg();
    [$project, $comps] = cprProject(2);
    $participant = Participant::factory()->forProject($project)->withStatus('in_corso')->create();

    $retriable = cprSession($participant, $project, $comps[0]->code, ['status' => 'error', 'error_count' => InterviewSession::MAX_ERROR_ATTEMPTS - 1]);
    $exhausted = cprSession($participant, $project, $comps[1]->code, ['status' => 'error', 'error_count' => InterviewSession::MAX_ERROR_ATTEMPTS]);

    expect(CompetencyProgress::isOpen($retriable))->toBeTrue()
        ->and(CompetencyProgress::isOpen($exhausted))->toBeFalse();
});

test('isOpen is false for completed, timeout and skipped sessions', function (): void {
    cprOrg();
    [$project, $comps] = cprProject(3);
    $participant = Participant::factory()->forProject($project)->withStatus('in_corso')->create();

    foreach (['completed', 'timeout', 'skipped'] as $i => $status) {
        expect(CompetencyProgress::isOpen(cprSession($participant, $project, $comps[$i]->code, ['status' => $status])))
            ->toBeFalse($status.' closes a competency');
    }
});

test('ordered returns the project rows by position and only that project', function (): void {
    cprOrg();
    [$projectA, $shared] = cprProject(3);
    [$projectB] = cprProject(3, array_reverse($shared));

    $a = app(CompetencyProgress::class)->ordered($projectA->id);
    $b = app(CompetencyProgress::class)->ordered($projectB->id);

    expect($a->pluck('competency_code')->all())->toBe(array_map(fn (Competency $c) => $c->code, $shared))
        ->and($a->pluck('position')->all())->toBe([0, 1, 2])
        ->and($b->pluck('competency_code')->all())->toBe(array_map(fn (Competency $c) => $c->code, array_reverse($shared)))
        ->and($a->first()->competency_id)->toBe($shared[0]->id);
});

test('resolveNext offers the first competency with its ordinal, total and position as question_index', function (): void {
    cprOrg();
    [$project, $comps] = cprProject(3);
    $participant = Participant::factory()->forProject($project)->withStatus('in_attesa')->create();

    expect(app(CompetencyProgress::class)->resolveNext($participant->id, $project->id))->toBe([
        'competency_code' => $comps[0]->code,
        'question_index' => 0,
        'competency_ordinal' => 1,
        'total_competencies' => 3,
    ]);
});

test('resolveNext skips closed competencies and resumes a pending one', function (): void {
    cprOrg();
    [$project, $comps] = cprProject(3);
    $participant = Participant::factory()->forProject($project)->withStatus('in_corso')->create();

    cprSession($participant, $project, $comps[0]->code, ['status' => 'completed']);
    cprSession($participant, $project, $comps[1]->code, ['status' => 'pending']);

    expect(app(CompetencyProgress::class)->resolveNext($participant->id, $project->id))->toBe([
        'competency_code' => $comps[1]->code,
        'question_index' => 1,
        'competency_ordinal' => 2,
        'total_competencies' => 3,
    ]);
});

test('resolveNext flags a re-offer for an errored session with attempts left and skips an exhausted one', function (): void {
    cprOrg();
    [$project, $comps] = cprProject(3);
    $participant = Participant::factory()->forProject($project)->withStatus('in_corso')->create();

    cprSession($participant, $project, $comps[0]->code, ['status' => 'error', 'error_count' => InterviewSession::MAX_ERROR_ATTEMPTS]);
    cprSession($participant, $project, $comps[1]->code, ['status' => 'error', 'error_count' => 1]);

    $next = app(CompetencyProgress::class)->resolveNext($participant->id, $project->id);

    expect($next)->toBe([
        'competency_code' => $comps[1]->code,
        'question_index' => 1,
        'competency_ordinal' => 2,
        'total_competencies' => 3,
        'reoffer' => true,
    ]);
});

test('resolveNext is null once every competency is closed', function (): void {
    cprOrg();
    [$project, $comps] = cprProject(2);
    $participant = Participant::factory()->forProject($project)->withStatus('in_corso')->create();

    cprSession($participant, $project, $comps[0]->code, ['status' => 'completed']);
    cprSession($participant, $project, $comps[1]->code, ['status' => 'timeout']);

    expect(app(CompetencyProgress::class)->resolveNext($participant->id, $project->id))->toBeNull();
});
