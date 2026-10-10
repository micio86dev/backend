<?php

declare(strict_types=1);

/**
 * ComposeCompetencyPrompt — the three early "not found" exits throw
 * `CompositionException` (the controller maps each to the 422 `composition_error`),
 * and a valid input returns a `ComposedPrompt` stamped with the stored set it came
 * from (null on the `baseline` source). The behaviour was MOVED from the controller
 * (API-03a), so there is no RED phase: each assertion is proved able to fail by
 * mutating the action.
 */

use App\Actions\Interview\ComposeCompetencyPrompt;
use App\DTOs\Conversation\ComposedPrompt;
use App\DTOs\Conversation\SpokenOpening;
use App\Enums\AssessmentType;
use App\Exceptions\Conversation\CompositionException;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Project;
use App\Models\ProjectQuestion;
use App\Models\Role;
use App\Services\Conversation\PromptSetResolver;
use App\Support\Catalogue\CatalogueRevisionResolver;

beforeEach(function (): void {
    PromptSetResolver::flushCache();
});

/**
 * A standard project, its role and one competency with indicators, all in the
 * project's pinned revision.
 *
 * @return array{0: Project, 1: Competency, 2: int}
 */
function ccpFixture(): array
{
    $org = casOrg();

    $project = casInTenant($org, fn (): Project => Project::factory()->create([
        'status' => 'active',
        'assessment_type' => 'standard',
        'role_code' => 'CCP_ROLE',
        'language' => 'en',
    ]));
    $role = Role::factory()->create(['code' => 'CCP_ROLE']);
    $competency = Competency::factory()->create(['code' => 'CCP_COMP']);

    foreach ([0, 1] as $i) {
        $indicator = new BarsIndicator;
        $indicator->forceFill([
            'role_id' => $role->id,
            'competency_id' => $competency->id,
            'text' => ['en' => "indicator {$i}"],
            'anchor_5' => ['en' => "five {$i}"],
            'anchor_3' => ['en' => "three {$i}"],
            'anchor_1' => ['en' => "one {$i}"],
            'position' => $i,
        ]);
        $indicator->save();
    }

    $revisionId = app(CatalogueRevisionResolver::class)->tryForProject($project);
    expect($revisionId)->not->toBeNull();

    return [$project, $competency, $revisionId];
}

/** @param  list<string>  $primaries */
function ccpHandle(Project $project, ?int $revisionId, ?Competency $competency, string $code = 'CCP_COMP', array $primaries = ['Tell me about a conflict.']): ComposedPrompt
{
    return app(ComposeCompetencyPrompt::class)->handle(
        $project,
        AssessmentType::Standard,
        $code,
        $revisionId,
        $competency,
        $primaries,
        4,
        SpokenOpening::primary(1),
    );
}

test('no catalogue revision throws CompositionException', function (): void {
    [$project, $competency] = ccpFixture();

    expect(fn () => ccpHandle($project, null, $competency))->toThrow(CompositionException::class);
});

test('a role code absent from the pinned revision throws CompositionException', function (): void {
    [$project, $competency, $revisionId] = ccpFixture();
    $project->role_code = 'NOT_IN_REVISION';

    expect(fn () => ccpHandle($project, $revisionId, $competency))->toThrow(CompositionException::class);
});

test('a competency not found throws CompositionException', function (): void {
    [$project, , $revisionId] = ccpFixture();

    expect(fn () => ccpHandle($project, $revisionId, null, 'NOT_A_COMPETENCY'))->toThrow(CompositionException::class);
});

test('a valid input composes a prompt stamped with the stored set on the db source', function (): void {
    config(['conversation.prompt_source' => 'db']);
    [$project, $competency, $revisionId] = ccpFixture();
    ProjectQuestion::create([
        'project_id' => $project->id,
        'competency_id' => $competency->id,
        'text' => ['en' => 'Tell me about a conflict.'],
        'position' => 0,
    ]);

    $composed = ccpHandle($project, $revisionId, $competency);

    expect($composed->text)->not->toBe('')
        ->and($composed->promptSetRef)->toMatch('/^s\d+\.[0-9a-f]{12}$/');
});

test('a valid input on the baseline source carries no stored-set stamp', function (): void {
    config(['conversation.prompt_source' => 'baseline']);
    [$project, $competency, $revisionId] = ccpFixture();

    $composed = ccpHandle($project, $revisionId, $competency);

    expect($composed->text)->not->toBe('')
        ->and($composed->promptSetRef)->toBeNull();
});
