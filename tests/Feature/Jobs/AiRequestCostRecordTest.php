<?php

declare(strict_types=1);

/**
 * ai_requests as a COST record (C13, observability delta / design D1).
 *
 * The distinction these tests defend: an `ai_requests` row is not a log of
 * successful scoring, it is a record of money spent. A provider call that
 * returns unparseable JSON has still been made and still been billed.
 *
 * Before C13 there was exactly one `AiRequest::create()` in the codebase, on
 * the success path, inside the transaction that persists competency results. So
 * four classes of billed failure left no trace at all, and a rollback of the
 * results discarded the record of a call that really happened.
 *
 * Every test here would have failed before that change.
 */

use App\Contracts\LLMProvider;
use App\Enums\AiRequestFailureReason;
use App\Jobs\ScoreEvaluationJob;
use App\Models\AiRequest;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Observability\AiRequestCostEstimator;
use App\Support\Tenancy\TenantResolver;
use App\Testing\CassetteLLMProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function costOrg(): Organization
{
    return Organization::factory()->create();
}

function costProject(Organization $org): Project
{
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    return Project::factory()->create(['status' => 'active', 'language' => 'en']);
}

function costParticipant(Organization $org, Project $project): Participant
{
    $p = new Participant;
    $p->forceFill([
        'organization_id' => $org->id,
        'project_id' => $project->id,
        'candidate_ref' => 'cost-'.uniqid(),
        'display_name' => 'Cost Record Test',
        'status' => 'in_valutazione',
    ]);
    $p->save();

    return $p->fresh();
}

/** Run the scoring job against a cassette returning $body for the competency. */
function costRun(string $body): ?AiRequest
{
    $org = costOrg();
    $project = costProject($org);
    $participant = costParticipant($org, $project);
    $setup = setupScoringCompetency($org, $project, $participant, 'COL');

    app()->instance(
        LLMProvider::class,
        new CassetteLLMProvider([$setup['competency']->code => $body])
    );

    (new ScoreEvaluationJob($participant->id))->handle();

    return AiRequest::withoutGlobalScopes()->first();
}

// ─── The failure paths that used to vanish ───────────────────────────────────

test('a billed call whose response will not parse is still recorded', function (): void {
    // The provider answered, the tokens were consumed, the invoice will show
    // it. Returning without a row here is how spend went missing.
    $row = costRun('this is not json at all');

    expect($row)->not->toBeNull();
    expect($row->success)->toBeFalse();
    expect($row->failure_reason)->toBe(AiRequestFailureReason::ParseError);
});

test('a call whose indicator count is wrong is still recorded', function (): void {
    // Valid JSON, wrong shape: two behaviours where the framework defines one.
    $row = costRun(json_encode([
        'behaviors' => [
            ['indicator' => 'A', 'score' => 5, 'explanation' => 'x', 'excerpts' => ['I worked collaboratively on multiple projects']],
            ['indicator' => 'B', 'score' => 3, 'explanation' => 'y', 'excerpts' => ['I worked collaboratively on multiple projects']],
        ],
    ]));

    expect($row)->not->toBeNull();
    expect($row->success)->toBeFalse();
});

test('the failure reason never leaks the provider payload', function (): void {
    // A provider error string can echo prompt content, and prompts contain
    // candidate answers. This table is read by an org-scoped cost dashboard, so
    // a payload fragment here is a confidentiality leak with a UI in front of
    // it. The reason is a closed machine key; the raw body must not appear.
    $secret = 'CANDIDATE-SAID-SOMETHING-PRIVATE';
    $row = costRun('not json '.$secret);

    expect($row)->not->toBeNull();
    expect($row->failure_reason?->value)->toBeIn(
        array_map(fn (AiRequestFailureReason $r): string => $r->value, AiRequestFailureReason::cases())
    );

    foreach ($row->getAttributes() as $value) {
        if (is_string($value)) {
            expect($value)->not->toContain($secret);
        }
    }
});

// ─── Every recorded call is attributable ─────────────────────────────────────

test('a successful call records provider, model and an estimated cost', function (): void {
    $row = costRun(json_encode([
        'behaviors' => [[
            'indicator' => 'Work effectively with others',
            'score' => 5,
            'explanation' => 'Strong evidence of collaboration',
            'excerpts' => ['I worked collaboratively on multiple projects'],
        ]],
    ]));

    expect($row)->not->toBeNull();
    expect($row->success)->toBeTrue();
    expect($row->failure_reason)->toBeNull();

    // "estimated cost per provider and model" is a promise the observability
    // spec makes; without these columns it was unkeepable, which is why C11
    // shipped token counts instead of money.
    expect($row->provider)->not->toBeEmpty();
    expect($row->model)->not->toBeEmpty();
    expect((float) $row->estimated_cost_usd)->toBeGreaterThanOrEqual(0.0);
});

// ─── The estimator ───────────────────────────────────────────────────────────

test('the cost estimate is derived from the configured rate, not invented', function (): void {
    config()->set('scoring.cost_rates_usd_per_million', [
        'test-model' => ['input' => 2.0, 'output' => 10.0],
    ]);

    // 1M in at $2 + 0.5M out at $10 = 2.00 + 5.00
    expect((new AiRequestCostEstimator)->estimate('test-model', 1_000_000, 500_000))
        ->toBe(7.0);
});

test('an unknown model costs zero rather than throwing', function (): void {
    // A missing rate must never be the thing that stops a scoring job or loses
    // the record of a call that was actually made. A zero-cost row is visible
    // in the dashboard as the anomaly it is.
    config()->set('scoring.cost_rates_usd_per_million', []);

    expect((new AiRequestCostEstimator)->estimate('never-heard-of-it', 1000, 1000))
        ->toBe(0.0);
});

test('sub-cent costs are not rounded away', function (): void {
    config()->set('scoring.cost_rates_usd_per_million', [
        'cheap' => ['input' => 1.0, 'output' => 5.0],
    ]);

    // 1000 in + 500 out on a cheap model is a fraction of a cent. Rounding to
    // 2dp would floor a whole campaign's spend to zero and let the dashboard
    // confidently report that nothing was spent.
    expect((new AiRequestCostEstimator)->estimate('cheap', 1000, 500))
        ->toBeGreaterThan(0.0);
});
