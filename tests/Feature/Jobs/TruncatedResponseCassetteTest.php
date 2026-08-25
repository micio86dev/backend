<?php

declare(strict_types=1);

/**
 * RED+GREEN — A1.12: truncated-response cassette (C13, design.md D3/D4).
 *
 * Asserts:
 * - `ai_requests.failure_reason = 'truncated'` (never `llm_parse_error`).
 * - `CompetencyResult.unscorable_reason = 'llm_truncated'` (never `llm_parse_error`).
 * - The scoring loop never reaches `json_decode()` — no `IndicatorScore` row
 *   is persisted (there is nothing to parse into: the short-circuit happens
 *   before any parse attempt, D4).
 */

use App\Contracts\LLMProvider;
use App\Enums\AiRequestFailureReason;
use App\Jobs\ScoreEvaluationJob;
use App\Models\AiRequest;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\IndicatorScore;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use App\Testing\CassetteLLMProvider;
use App\Testing\CassetteResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a truncated response is recorded as truncated/llm_truncated, never llm_parse_error, without ever parsing', function (): void {
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['status' => 'active', 'language' => 'en']);

    $participant = new Participant;
    $participant->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'truncated-'.uniqid(),
        'display_name' => 'Truncated Response Test',
        'status' => 'in_valutazione',
    ]);
    $participant->save();
    $participant = $participant->fresh();

    $setup = setupScoringCompetency($org, $project, $participant, 'PRS');
    $competencyCode = $setup['competency']->code;

    $fixture = require base_path('tests/Fixtures/cassettes/truncated_response.php');

    $cassette = new CassetteLLMProvider([
        $competencyCode => new CassetteResponse(
            content: $fixture['content'],
            finishReason: $fixture['finish_reason'],
        ),
    ]);
    app()->instance(LLMProvider::class, $cassette);

    $job = new ScoreEvaluationJob($participant->id);
    $job->handle();

    $evaluation = Evaluation::withoutGlobalScopes()
        ->where('participant_id', $participant->id)
        ->first();

    expect($evaluation)->not->toBeNull();

    $aiRequest = AiRequest::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $competencyCode)
        ->first();

    expect($aiRequest)->not->toBeNull('An ai_requests row must exist — the call was made and billed.')
        ->and($aiRequest->success)->toBeFalse()
        ->and($aiRequest->failure_reason)->toBe(AiRequestFailureReason::Truncated)
        ->and($aiRequest->failure_reason)->not->toBe(AiRequestFailureReason::ParseError)
        ->and($aiRequest->finish_reason)->toBe('max_tokens');

    $competencyResult = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $competencyCode)
        ->first();

    expect($competencyResult)->not->toBeNull()
        ->and($competencyResult->score)->toBeNull()
        ->and($competencyResult->valid)->toBeFalse()
        ->and($competencyResult->unscorable_reason)->toBe('llm_truncated')
        ->and($competencyResult->unscorable_reason)->not->toBe('llm_parse_error');

    // The scoring loop never reached json_decode(): nothing to parse into,
    // so no IndicatorScore row exists for this CompetencyResult.
    $indicatorScoreCount = IndicatorScore::where('competency_result_id', $competencyResult->id)->count();
    expect($indicatorScoreCount)->toBe(0, 'No IndicatorScore row should exist — the parser was never reached.');

    expect($cassette->callCount())->toBe(1, 'Increment A ships no retry — exactly one call is made.');
});
