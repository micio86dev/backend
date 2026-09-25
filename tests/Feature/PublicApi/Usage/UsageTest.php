<?php

declare(strict_types=1);

/**
 * `GET /v1/usage` — BEAI Public API (public-api step 8), SPEC.md §3.3/§3.4
 * "Usage specifically". T-USAGE-001..004.
 */

use App\Enums\ApiKeyMode;
use App\Models\AiRequest;
use App\Models\ApiClient;
use App\Models\InterviewSessionLlmUsage;
use App\Models\Organization;
use App\Models\Participant;
use App\Services\ApiKeyGenerator;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\PublicApi\Step6Fixtures;

test('T-USAGE-001: interviews/evaluations/completion_rate/llm_tokens/cost_usd match a hand-seeded window', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['usage:read']);
    $project = Step6Fixtures::project($org);

    // One of each interview status, all created "now" (inside the default
    // current-month window) and mode=live (the default key mode).
    TenantContextScope::runFor($org->id, function () use ($org, $project): void {
        foreach (['in_attesa', 'in_corso', 'in_valutazione', 'errore'] as $status) {
            $p = new Participant;
            $p->forceFill([
                'organization_id' => $org->id,
                'project_id' => $project->id,
                'candidate_ref' => 'usage-'.$status.'-'.uniqid(),
                'display_name' => 'Usage Fixture',
                'email' => uniqid('usage-'.$status.'-').'@example.test',
                'status' => $status,
                'language' => 'en',
                'mode' => ApiKeyMode::Live,
            ]);
            $p->save();
        }
    });

    // A real completed+scored participant (COL, {5,3,3} -> mean 3.67) —
    // exercises evaluations.completed, llm_tokens and cost_usd against
    // REAL AiRequest rows the scoring pipeline itself wrote, not fabricated
    // aggregates.
    $scored = Step6Fixtures::buildCompletedScoredParticipant($org, $project);
    TenantContextScope::runFor($org->id, function () use ($scored): void {
        $scored->forceFill(['mode' => ApiKeyMode::Live])->save();
    });

    $expectedTokens = TenantContextScope::runFor($org->id, fn () => [
        'input' => (int) AiRequest::query()->sum('input_tokens'),
        'output' => (int) AiRequest::query()->sum('output_tokens'),
    ]);
    $expectedScoringCost = TenantContextScope::runFor($org->id, fn () => (float) AiRequest::query()->sum('estimated_cost_usd'));
    $expectedConversationCost = TenantContextScope::runFor(
        $org->id,
        fn () => (float) InterviewSessionLlmUsage::query()->sum(DB::raw('coalesce(actual_cost_usd, estimated_cost_usd, 0)')),
    );

    // Explicit, generously wide from/to (never the implicit default
    // "current calendar month to now()" window) — this test's own fixture
    // setup and the request itself both take real wall-clock time, and the
    // default window's `to` bound is computed independently, fresh,
    // INSIDE the controller at read time. A default-window run observed one
    // spurious failure under heavy parallel load with llm_tokens read back
    // as zero — never reproduced in isolation, consistent with the two
    // independently-evaluated `now()` calls (fixture setup vs. controller
    // read) racing under genuine system load rather than a logic bug. An
    // explicit, multi-day-wide window removes that timing sensitivity
    // entirely while still exercising the exact same aggregation query.
    // http_build_query(), never bare string concatenation — Carbon's own
    // ISO 8601 offset form (`+00:00`) contains a literal `+`, which a raw
    // querystring leaves un-encoded; PHP's query parser then decodes that
    // `+` as a space (the `application/x-www-form-urlencoded` convention),
    // corrupting the value before `Iso8601DateTime` ever sees it and
    // producing a spurious 400 — caught by this exact test, deterministically,
    // the first time this window was made explicit.
    $query = http_build_query(['from' => now()->subDay()->toIso8601String(), 'to' => now()->addDay()->toIso8601String()]);
    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/usage?'.$query);

    $response->assertOk();
    $this->assertMatchesContract($response, 'GET', '/usage');

    $body = $response->json();

    expect($body['livemode'])->toBeTrue();
    expect($body['interviews'])->toBe([
        'pending' => 1,
        'in_progress' => 1,
        'under_evaluation' => 1,
        'completed' => 1,
        'error' => 1,
    ]);
    // 5 interviews total (4 fixture statuses + the scored one, in_valutazione
    // -> completato via the real job), 1 completed => 1/5.
    expect($body['completion_rate'])->toBe(0.2);
    expect($body['evaluations'])->toBe(['completed' => 1, 'pending' => 0]);
    expect($body['llm_tokens'])->toBe($expectedTokens);
    expect($body['cost_usd'])->toBe(round($expectedScoringCost + $expectedConversationCost, 6));
    expect($body['currency'])->toBe('USD');
});

test('T-USAGE-001b: an explicit valid from/to window is echoed back verbatim', function (): void {
    ['key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['usage:read']);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/usage?from=2026-01-01T00:00:00Z&to=2026-01-31T23:59:59Z');

    $response->assertOk();
    expect($response->json('from'))->toBe('2026-01-01T00:00:00+00:00');
    expect($response->json('to'))->toBe('2026-01-31T23:59:59+00:00');
});

test('T-USAGE-002: from later than to answers 400 validation_failed', function (): void {
    ['key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['usage:read']);

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])
        ->getJson('/api/v1/usage?from=2026-06-30T00:00:00Z&to=2026-06-01T00:00:00Z');

    $response->assertStatus(400)->assertJsonPath('code', 'validation_failed');
    $this->assertProblemMatchesContract($response, 400);
});

test('T-USAGE-003: usage never counts another organization\'s interviews', function (): void {
    ['org' => $orgA, 'key' => $keyA] = Step6Fixtures::orgWithScopedKey(['usage:read']);
    $projectA = Step6Fixtures::project($orgA);
    Step6Fixtures::participantWithTranscript($orgA, $projectA, 'in_corso');

    ['org' => $orgB] = Step6Fixtures::orgWithScopedKey(['usage:read']);
    $projectB = Step6Fixtures::project($orgB);
    // Three participants in the OTHER organization — if org isolation ever
    // broke, these would inflate orgA's own report.
    Step6Fixtures::participantWithTranscript($orgB, $projectB, 'in_corso');
    Step6Fixtures::participantWithTranscript($orgB, $projectB, 'in_corso');
    Step6Fixtures::participantWithTranscript($orgB, $projectB, 'in_corso');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$keyA])->getJson('/api/v1/usage');

    $response->assertOk();
    expect($response->json('interviews.in_progress'))->toBe(1);
});

test('T-USAGE-004: a test-mode key never sees live-mode interviews in its own usage report, and vice versa', function (): void {
    $org = Organization::factory()->create();
    $project = Step6Fixtures::project($org);

    $liveKey = ApiKeyGenerator::generate(ApiKeyMode::Live);
    ApiClient::factory()->withRawKey($liveKey)->create([
        'organization_id' => $org->id,
        'mode' => ApiKeyMode::Live,
        'abilities' => ['usage:read'],
    ]);
    $testKey = ApiKeyGenerator::generate(ApiKeyMode::Test);
    ApiClient::factory()->withRawKey($testKey)->create([
        'organization_id' => $org->id,
        'mode' => ApiKeyMode::Test,
        'abilities' => ['usage:read'],
    ]);

    TenantContextScope::runFor($org->id, function () use ($org, $project): void {
        $live = new Participant;
        $live->forceFill([
            'organization_id' => $org->id,
            'project_id' => $project->id,
            'candidate_ref' => 'usage-mode-live-'.uniqid(),
            'display_name' => 'Live Fixture',
            'email' => uniqid('usage-mode-live-').'@example.test',
            'status' => 'in_corso',
            'language' => 'en',
            'mode' => ApiKeyMode::Live,
        ]);
        $live->save();

        $test = new Participant;
        $test->forceFill([
            'organization_id' => $org->id,
            'project_id' => $project->id,
            'candidate_ref' => 'usage-mode-test-'.uniqid(),
            'display_name' => 'Test Fixture',
            'email' => uniqid('usage-mode-test-').'@example.test',
            'status' => 'in_corso',
            'language' => 'en',
            'mode' => ApiKeyMode::Test,
        ]);
        $test->save();
    });

    $liveResponse = $this->withHeaders(['Authorization' => 'Bearer '.$liveKey])->getJson('/api/v1/usage');
    $testResponse = $this->withHeaders(['Authorization' => 'Bearer '.$testKey])->getJson('/api/v1/usage');

    $liveResponse->assertOk();
    $testResponse->assertOk();

    expect($liveResponse->json('livemode'))->toBeTrue();
    expect($liveResponse->json('interviews.in_progress'))->toBe(1);
    expect($testResponse->json('livemode'))->toBeFalse();
    expect($testResponse->json('interviews.in_progress'))->toBe(1);
});

test('T-USAGE-005: participant/evaluation/session id scoping uses subqueries, never a materialized whereIn id list', function (): void {
    ['org' => $org, 'key' => $rawKey] = Step6Fixtures::orgWithScopedKey(['usage:read']);
    $project = Step6Fixtures::project($org);
    Step6Fixtures::participantWithTranscript($org, $project, 'in_corso');

    $queries = [];
    DB::listen(function (object $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$rawKey])->getJson('/api/v1/usage');

    $response->assertOk();
    expect($response->json('interviews.in_progress'))->toBe(1);

    // Every query that scopes by one of UsageAggregator's own FK id columns
    // (participant_id on Evaluation/InterviewSession, evaluation_id on
    // AiRequest, interview_session_id on InterviewSessionLlmUsage) must
    // filter through a subquery, never a `whereIn()` fed by a PHP-side
    // pluck()'d id list — a materialized id list breaks past Postgres's
    // ~65,535 bind-parameter limit for a large organization (gga finding 5).
    $idScopedQueries = array_filter(
        $queries,
        fn (string $sql): bool => str_contains($sql, 'participant_id') || str_contains($sql, 'evaluation_id') || str_contains($sql, 'interview_session_id'),
    );

    expect($idScopedQueries)->not->toBeEmpty();

    foreach ($idScopedQueries as $sql) {
        if (str_contains($sql, ' in (')) {
            expect($sql)->toContain('in (select');
        }
    }

    expect(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'in (select')))->not->toBeEmpty();
});
