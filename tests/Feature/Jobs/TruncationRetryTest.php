<?php

declare(strict_types=1);

/**
 * RED+GREEN — B1.4: truncation-only retry at an enlarged budget
 * (C13, design.md D8, scoring-engine's "Truncation-Only Retry At An Enlarged
 * Budget" requirement, observability's "Each Truncation Retry Attempt Gets
 * Its Own ai_requests Row" requirement).
 *
 * Asserts:
 * (a) first-truncated / second-complete → TWO ai_requests rows (one
 *     success=false/truncated, one success=true), and the competency scores
 *     normally from the RETRIED response.
 * (b) both-truncated → CassetteLLMProvider::callCount() === 2 — no third
 *     call, ever — unscorable_reason = 'llm_truncated', and BOTH
 *     ai_requests rows are success=false.
 * (c) a fence/prose failure (llm_parse_error, not truncated) is never
 *     retried — exactly ONE ai_requests row. This is the carve-out D4
 *     FIX-9 stands for: only ResponseTruncated is retryable.
 */

use App\Contracts\LLMProvider;
use App\Enums\AiRequestFailureReason;
use App\Jobs\ScoreEvaluationJob;
use App\Models\AiRequest;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use App\Testing\CassetteLLMProvider;
use App\Testing\CassetteResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Organization, 1: Project, 2: Participant}
 */
function truncationRetrySetup(): array
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
        'candidate_ref' => 'truncation-retry-'.uniqid(),
        'display_name' => 'Truncation Retry Test',
        'status' => 'in_valutazione',
    ]);
    $participant->save();

    return [$org, $project, $participant->fresh()];
}

// A complete, valid single-indicator body — shaped for
// tests/Helpers/ScoringFixtures.php::setupScoringCompetency()'s single
// indicator ('Work effectively with others') and utterance excerpt.
const TRUNCATION_RETRY_COMPLETE_BODY = '{"behaviors": [{"indicator": "Work effectively with others", "score": 5, "explanation": "Strong evidence of collaboration.", "excerpts": ["I worked collaboratively on multiple projects"]}]}';

test('a) first attempt truncates, retry at double budget completes: two ai_requests rows, scores normally', function (): void {
    config([
        'scoring.truncation_retry.enabled' => true,
        'scoring.truncation_retry.max_attempts' => 1,
        'scoring.truncation_retry.budget_multiplier' => 2.0,
        'scoring.truncation_retry.budget_ceiling' => 8192,
    ]);

    [$org, $project, $participant] = truncationRetrySetup();
    $setup = setupScoringCompetency($org, $project, $participant, 'PRS');
    $competencyCode = $setup['competency']->code;

    $cassette = new CassetteLLMProvider([
        $competencyCode => [
            new CassetteResponse(content: '{"behaviors": [{"indicator": "Work eff', finishReason: 'max_tokens'),
            new CassetteResponse(content: TRUNCATION_RETRY_COMPLETE_BODY, finishReason: 'end_turn'),
        ],
    ]);
    app()->instance(LLMProvider::class, $cassette);

    (new ScoreEvaluationJob($participant->id))->handle();

    expect($cassette->callCount())->toBe(2, 'Exactly one retry: the original call plus the enlarged-budget retry.');

    $evaluation = Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->first();

    $aiRequests = AiRequest::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $competencyCode)
        ->orderBy('id')
        ->get();

    expect($aiRequests)->toHaveCount(2, 'Every attempt is billed and logged — its own row, never reused.')
        ->and($aiRequests[0]->success)->toBeFalse()
        ->and($aiRequests[0]->failure_reason)->toBe(AiRequestFailureReason::Truncated)
        ->and($aiRequests[1]->success)->toBeTrue()
        ->and($aiRequests[1]->failure_reason)->toBeNull()
        ->and($aiRequests[0]->id)->not->toBe($aiRequests[1]->id);

    $result = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $competencyCode)
        ->first();

    expect($result)->not->toBeNull()
        ->and($result->unscorable_reason)->toBeNull()
        ->and($result->score)->toBe(5.0)
        ->and($result->valid)->toBeTrue();
});

test('b) both the original call and the retry truncate: exactly two calls ever, both rows failed, finalized llm_truncated', function (): void {
    config([
        'scoring.truncation_retry.enabled' => true,
        'scoring.truncation_retry.max_attempts' => 1,
        'scoring.truncation_retry.budget_multiplier' => 2.0,
        'scoring.truncation_retry.budget_ceiling' => 8192,
    ]);

    [$org, $project, $participant] = truncationRetrySetup();
    $setup = setupScoringCompetency($org, $project, $participant, 'PRS');
    $competencyCode = $setup['competency']->code;

    $cassette = new CassetteLLMProvider([
        $competencyCode => [
            new CassetteResponse(content: '{"behaviors": [{"indicator": "Work eff', finishReason: 'max_tokens'),
            new CassetteResponse(content: '{"behaviors": [{"indicator": "Work eff', finishReason: 'max_tokens'),
        ],
    ]);
    app()->instance(LLMProvider::class, $cassette);

    (new ScoreEvaluationJob($participant->id))->handle();

    expect($cassette->callCount())->toBe(2, 'No third call, ever — the cap is exactly one retry.');

    $evaluation = Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->first();

    $aiRequests = AiRequest::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $competencyCode)
        ->get();

    expect($aiRequests)->toHaveCount(2)
        ->and($aiRequests->every(fn (AiRequest $r): bool => $r->success === false))->toBeTrue()
        ->and($aiRequests->every(fn (AiRequest $r): bool => $r->failure_reason === AiRequestFailureReason::Truncated))->toBeTrue();

    $result = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $competencyCode)
        ->first();

    expect($result)->not->toBeNull()
        ->and($result->unscorable_reason)->toBe('llm_truncated')
        ->and($result->score)->toBeNull();
});

test('c) a non-truncated fence/prose failure is never retried at an enlarged budget: exactly one ai_requests row', function (): void {
    config([
        'scoring.truncation_retry.enabled' => true,
        'scoring.truncation_retry.max_attempts' => 1,
        'scoring.truncation_retry.budget_multiplier' => 2.0,
        'scoring.truncation_retry.budget_ceiling' => 8192,
    ]);

    [$org, $project, $participant] = truncationRetrySetup();
    $setup = setupScoringCompetency($org, $project, $participant, 'STG');
    $competencyCode = $setup['competency']->code;

    $fixture = require base_path('tests/Fixtures/cassettes/malformed_negative.php');
    $cassette = new CassetteLLMProvider([$competencyCode => $fixture['content']]);
    app()->instance(LLMProvider::class, $cassette);

    (new ScoreEvaluationJob($participant->id))->handle();

    expect($cassette->callCount())->toBe(1, 'A parse/validation failure is deterministic — retrying it would just fail identically (D4 FIX-9).');

    $evaluation = Evaluation::withoutGlobalScopes()->where('participant_id', $participant->id)->first();

    $aiRequests = AiRequest::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $competencyCode)
        ->get();

    expect($aiRequests)->toHaveCount(1)
        ->and($aiRequests[0]->failure_reason)->toBe(AiRequestFailureReason::ParseError);

    $result = CompetencyResult::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $competencyCode)
        ->first();

    expect($result)->not->toBeNull()
        ->and($result->unscorable_reason)->toBe('llm_parse_error');
});
