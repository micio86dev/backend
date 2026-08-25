<?php

declare(strict_types=1);

/**
 * RED+GREEN — A2.5: fenced-response cassette + negative cassettes
 * (C13, design.md D5).
 *
 * Asserts:
 * - A markdown-fenced JSON response parses successfully (scores normally).
 * - A negative cassette (fence + trailing prose containing a brace) still
 *   hard-fails as `llm_parse_error` — never silently discards the brace and
 *   scores the clean-looking fenced JSON.
 * - A negative cassette (plausible-looking-but-malformed body) still
 *   hard-fails as `llm_parse_error`.
 */

use App\Contracts\LLMProvider;
use App\Jobs\ScoreEvaluationJob;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use App\Testing\CassetteLLMProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Organization, 1: Project, 2: Participant}
 */
function fencedCassetteSetup(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active', 'language' => 'en']);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'fenced-'.uniqid(),
        'display_name' => 'Fenced Response Test',
        'status' => 'in_valutazione',
    ]);
    $participant->save();

    return [$org, $project, $participant->fresh()];
}

test('a markdown-fenced JSON response parses successfully and scores normally', function (): void {
    [$org, $project, $participant] = fencedCassetteSetup();

    $setup = setupScoringCompetency($org, $project, $participant, 'COL');
    $competencyCode = $setup['competency']->code;

    $fixture = require base_path('tests/Fixtures/cassettes/fenced_response.php');
    $cassette = new CassetteLLMProvider([$competencyCode => $fixture['content']]);
    app()->instance(LLMProvider::class, $cassette);

    (new ScoreEvaluationJob($participant->id))->handle();

    $evaluation = Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->first();
    $result = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $competencyCode)
        ->first();

    expect($result)->not->toBeNull()
        ->and($result->unscorable_reason)->toBeNull()
        ->and($result->score)->toBe(5.0)
        ->and($result->valid)->toBeTrue();
});

test('negative: fence + trailing prose containing a brace still hard-fails as llm_parse_error', function (): void {
    [$org, $project, $participant] = fencedCassetteSetup();

    $setup = setupScoringCompetency($org, $project, $participant, 'STG');
    $competencyCode = $setup['competency']->code;

    $fixture = require base_path('tests/Fixtures/cassettes/fence_trailing_prose_negative.php');
    $cassette = new CassetteLLMProvider([$competencyCode => $fixture['content']]);
    app()->instance(LLMProvider::class, $cassette);

    (new ScoreEvaluationJob($participant->id))->handle();

    $evaluation = Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->first();
    $result = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $competencyCode)
        ->first();

    expect($result)->not->toBeNull()
        ->and($result->unscorable_reason)->toBe('llm_parse_error')
        ->and($result->score)->toBeNull();
});

test('negative: a plausible-looking-but-malformed body still hard-fails as llm_parse_error', function (): void {
    [$org, $project, $participant] = fencedCassetteSetup();

    $setup = setupScoringCompetency($org, $project, $participant, 'SLF');
    $competencyCode = $setup['competency']->code;

    $fixture = require base_path('tests/Fixtures/cassettes/malformed_negative.php');
    $cassette = new CassetteLLMProvider([$competencyCode => $fixture['content']]);
    app()->instance(LLMProvider::class, $cassette);

    (new ScoreEvaluationJob($participant->id))->handle();

    $evaluation = Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->first();
    $result = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $competencyCode)
        ->first();

    expect($result)->not->toBeNull()
        ->and($result->unscorable_reason)->toBe('llm_parse_error')
        ->and($result->score)->toBeNull();
});
