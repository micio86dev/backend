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
 *
 * UPDATED for B1 (design.md D8): `scoring.truncation_retry` now ships with
 * `enabled=true, max_attempts=1` as its SHIPPED DEFAULT (previously Increment
 * A shipped no such config block at all, so `ScoringFailureClassifier`
 * always returned `Terminal` and exactly one call was made). This cassette's
 * fixture returns the SAME truncated body on every call (a bare
 * `CassetteResponse`, not a `list`), so from B1 onward the job now retries
 * once at an enlarged budget, gets truncated again, and THEN finalizes as
 * unscorable — two calls, two `ai_requests` rows, both `failure_reason =
 * 'truncated'`. This is the deliberate, documented behavior change B1 makes
 * to every truncated call in production (see
 * tests/Feature/Jobs/TruncationRetryTest.php for the full retry-success /
 * both-truncated / never-retried-when-not-truncated matrix) — not a silent
 * regression of this test.
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

    $aiRequests = AiRequest::withoutGlobalScopes()
        ->where('evaluation_id', $evaluation->id)
        ->where('competency_code', $competencyCode)
        ->get();

    // B1: two rows — the original attempt plus the one enlarged-budget retry,
    // both truncated (this fixture returns the same cut-off body every call).
    // Every attempt is billed and logged — its own row, never reused (D8).
    expect($aiRequests)->toHaveCount(2, 'Every attempt (original + retry) gets its own ai_requests row.')
        ->and($aiRequests->every(fn (AiRequest $r): bool => $r->success === false))->toBeTrue()
        ->and($aiRequests->every(fn (AiRequest $r): bool => $r->failure_reason === AiRequestFailureReason::Truncated))->toBeTrue()
        ->and($aiRequests->every(fn (AiRequest $r): bool => $r->failure_reason !== AiRequestFailureReason::ParseError))->toBeTrue()
        ->and($aiRequests->every(fn (AiRequest $r): bool => $r->finish_reason === 'max_tokens'))->toBeTrue();

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

    expect($cassette->callCount())->toBe(2, 'B1 ships enabled=true/max_attempts=1 by default: original call + exactly one retry, no third.');
});
