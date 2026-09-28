<?php

declare(strict_types=1);

/**
 * RED — split-interview-controller design.md D2: ResolveInterviewDirective.
 *
 * A verbatim move of InterviewController::buildDirective() — see that method's
 * original docblock (preserved on the new class) for the `next_action` rules.
 * Uses real DB fixtures (CompetencyTally queries `project_competencies` and
 * `interview_sessions` directly — no seam to mock) with precise, minimal rows
 * so `$ended`/`$total` are exact per case, mirroring
 * tests/Feature/C7a/CompetencyTallyParityTest.php's fixture style.
 */

use App\Actions\Interview\ResolveInterviewDirective;
use App\Models\Competency;
use App\Models\InterviewSession;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;

/**
 * @return array{0: Project, 1: Participant}
 */
function ridFixture(?int $pauseEvery, int $totalCompetencies): array
{
    $org = casOrg();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create([
        'status' => 'active',
        'assessment_type' => 'standard',
        'pause_every_n_competencies' => $pauseEvery,
    ]);

    for ($i = 0; $i < $totalCompetencies; $i++) {
        $comp = Competency::factory()->create();
        DB::table('project_competencies')->insert([
            'project_id' => $project->id,
            'competency_id' => $comp->id,
            'position' => $i,
        ]);
    }

    $participant = casParticipant($org, $project);

    return [$project, $participant];
}

function ridMarkEnded(Participant $participant, Project $project, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        InterviewSession::create([
            'participant_id' => $participant->id,
            'project_id' => $project->id,
            'question_index' => $i,
            'competency_code' => 'X'.$i,
            'framework_version_id' => $project->framework_version_id,
            'provider' => 'heygen',
            'provider_session_ref' => 'ref-'.$i,
            'status' => 'completed',
            'started_at' => now()->subMinutes(5),
            'ended_at' => now()->subMinutes(4),
        ]);
    }
}

test('next_action is done when every competency has ended', function (): void {
    [$project, $participant] = ridFixture(pauseEvery: null, totalCompetencies: 3);
    ridMarkEnded($participant, $project, 3);

    $directive = (new ResolveInterviewDirective)->handle($participant->id, $project->id);

    expect($directive)->toBe([
        'ended_competencies' => 3,
        'total_competencies' => 3,
        'next_action' => 'done',
    ]);
});

test('next_action is pause on a pause_every_n_competencies boundary, not on the final competency', function (): void {
    // Ended=2, total=4, pauseEvery=2 → 2 % 2 === 0 → would be "pause" by the
    // modulus rule alone, and this case's total is NOT yet reached, so "done"
    // does not pre-empt it.
    [$project, $participant] = ridFixture(pauseEvery: 2, totalCompetencies: 4);
    ridMarkEnded($participant, $project, 2);

    $directive = (new ResolveInterviewDirective)->handle($participant->id, $project->id);

    expect($directive['next_action'])->toBe('pause');
});

test('"done" is evaluated before "pause" — the final competency never shows a pause screen', function (): void {
    // Ended=4, total=4, pauseEvery=2 → 4 % 2 === 0 too, but total is reached,
    // so "done" must win — this is the ordering the original docblock calls out
    // explicitly ("done is evaluated FIRST").
    [$project, $participant] = ridFixture(pauseEvery: 2, totalCompetencies: 4);
    ridMarkEnded($participant, $project, 4);

    $directive = (new ResolveInterviewDirective)->handle($participant->id, $project->id);

    expect($directive['next_action'])->toBe('done');
});

test('next_action is continue when nothing has ended and no pause is configured', function (): void {
    [$project, $participant] = ridFixture(pauseEvery: null, totalCompetencies: 3);

    $directive = (new ResolveInterviewDirective)->handle($participant->id, $project->id);

    expect($directive)->toBe([
        'ended_competencies' => 0,
        'total_competencies' => 3,
        'next_action' => 'continue',
    ]);
});

test('a null pause_every_n_competencies fails closed to continue, never pause', function (): void {
    [$project, $participant] = ridFixture(pauseEvery: null, totalCompetencies: 4);
    ridMarkEnded($participant, $project, 2);

    $directive = (new ResolveInterviewDirective)->handle($participant->id, $project->id);

    expect($directive['next_action'])->toBe('continue');
});
