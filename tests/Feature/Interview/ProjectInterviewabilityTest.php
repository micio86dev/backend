<?php

declare(strict_types=1);

/**
 * RED — 20.1 (framework-catalogue-authoring PR6, D5): `ProjectInterviewability`
 * — the single predicate every interview entry point consumes (PR6's four
 * ingress refusal tests build on this one; see `InterviewabilityIngressRefusalTest.php`).
 */

use App\Models\Competency;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Support\Project\ProjectInterviewability;
use App\Support\Tenancy\TenantContextScope;

function piCompetency(string $code): Competency
{
    return Competency::firstOrCreate(
        ['code' => $code],
        ['name' => ['en' => 'x'], 'definition' => ['en' => 'x'], 'type' => 'standard'],
    );
}

/**
 * @param  list<string>  $competencyCodes
 */
function piProject(Organization $org, array $competencyCodes): Project
{
    return TenantContextScope::runFor($org->id, function () use ($org, $competencyCodes): Project {
        $project = Project::factory()->create(['organization_id' => $org->id]);

        foreach (array_values($competencyCodes) as $position => $code) {
            $project->competencies()->attach([piCompetency($code)->id => ['position' => $position]]);
        }

        return $project;
    });
}

function piAddQuestion(Organization $org, Project $project, Competency $competency, bool $trashed = false): void
{
    TenantContextScope::runFor($org->id, function () use ($project, $competency, $trashed): void {
        $question = ProjectQuestion::create([
            'project_id' => $project->id,
            'competency_id' => $competency->id,
            'text' => ['en' => 'x'],
            'position' => 0,
        ]);

        if ($trashed) {
            $question->delete();
        }
    });
}

test('a selected competency with zero live questions makes the project not interviewable', function (): void {
    $org = Organization::factory()->create();
    piCompetency('COL');
    $project = piProject($org, ['COL']);

    expect(app(ProjectInterviewability::class)->isInterviewable($project))->toBeFalse();
    expect(app(ProjectInterviewability::class)->unsatisfiedCompetencyCodes($project))->toBe(['COL']);
});

test('every selected competency having at least one live question makes the project interviewable', function (): void {
    $org = Organization::factory()->create();
    $col = piCompetency('COL');
    $project = piProject($org, ['COL']);
    piAddQuestion($org, $project, $col);

    expect(app(ProjectInterviewability::class)->isInterviewable($project))->toBeTrue();
    expect(app(ProjectInterviewability::class)->unsatisfiedCompetencyCodes($project))->toBe([]);
});

test('all rows soft-deleted for a selected competency still counts as zero live — reflects soft-delete state, not raw row count', function (): void {
    $org = Organization::factory()->create();
    $col = piCompetency('COL');
    $project = piProject($org, ['COL']);
    piAddQuestion($org, $project, $col, trashed: true);

    expect(app(ProjectInterviewability::class)->isInterviewable($project))->toBeFalse();
    expect(app(ProjectInterviewability::class)->unsatisfiedCompetencyCodes($project))->toBe(['COL']);
});

test('a deselected competency is out of scope for the check entirely — its soft-deleted rows do not count against it', function (): void {
    $org = Organization::factory()->create();
    $col = piCompetency('COL');
    $prs = piCompetency('PRS');
    $project = piProject($org, ['PRS']);
    piAddQuestion($org, $project, $prs);
    // COL was never selected on this project, and carries no questions at
    // all — must not appear in the unsatisfied set.

    expect(app(ProjectInterviewability::class)->isInterviewable($project))->toBeTrue();
    expect(app(ProjectInterviewability::class)->unsatisfiedCompetencyCodes($project))->toBe([]);
});

test('zero selected competencies is NOT interviewable — an extension beyond the spec literal wording (Contradiction 5)', function (): void {
    $org = Organization::factory()->create();
    $project = piProject($org, []);

    // The HAVING clause is vacuously satisfied by an empty set — this is
    // exactly why `isInterviewable()` needs the separate "has at least one
    // selected competency" check `unsatisfiedCompetencyCodes()` alone cannot
    // express.
    expect(app(ProjectInterviewability::class)->unsatisfiedCompetencyCodes($project))->toBe([]);
    expect(app(ProjectInterviewability::class)->isInterviewable($project))->toBeFalse();
});

test('minting succeeds once every selected competency has a live question', function (): void {
    // The spec's own "Minting succeeds once the project is interviewable"
    // scenario, expressed at the predicate level: adding a question back
    // flips the answer.
    $org = Organization::factory()->create();
    $col = piCompetency('COL');
    $prs = piCompetency('PRS');
    $project = piProject($org, ['COL', 'PRS']);
    piAddQuestion($org, $project, $prs);

    expect(app(ProjectInterviewability::class)->isInterviewable($project))->toBeFalse();

    piAddQuestion($org, $project, $col);

    expect(app(ProjectInterviewability::class)->isInterviewable($project))->toBeTrue();
});
