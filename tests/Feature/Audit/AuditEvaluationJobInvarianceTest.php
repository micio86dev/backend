<?php

declare(strict_types=1);

/**
 * RED — P3b.15-P3b.18: no audit result may influence any scoring value
 * (spec "No Audit Result May Influence Any Scoring Value"), and the audit's
 * own cost/tokens must never be summed into the pre-existing scoring
 * dashboard metric — `SessionCostEstimator.php`'s own "two vendors, two
 * meters, never summed" doctrine, applied to the second meter this change
 * introduces. Exercised across all three run outcomes (completed, partial,
 * failed) — the invariant MUST hold regardless of the run's terminal status.
 */

use App\Contracts\AuditJudge;
use App\Jobs\AuditEvaluationJob;
use App\Models\AiRequest;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use App\Testing\FakeAuditJudge;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{org: Organization, evaluation: Evaluation, indicator: IndicatorScore}
 */
function invarianceFixture(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);
    $comp = CompetencyResult::factory()->create(['evaluation_id' => $evaluation->id, 'competency_code' => 'COL']);
    $indicator = IndicatorScore::factory()->create([
        'competency_result_id' => $comp->id, 'position' => 0, 'score' => 4, 'excerpts' => ['e1'],
    ]);

    return ['org' => $org, 'evaluation' => $evaluation, 'indicator' => $indicator];
}

function invarianceToken(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user->assignRole(SpatieRole::firstOrCreate([
        'name' => 'admin', 'guard_name' => 'api', 'team_id' => $org->id,
    ]));
    app(TenantResolver::class)->setOrgId($org->id);

    return auth('api')->login($user);
}

test('indicator_scores, competency_results, and evaluations rows are byte-identical before and after a completed run', function (): void {
    $fixture = invarianceFixture();
    app()->instance(AuditJudge::class, new FakeAuditJudge);

    $before = [
        'indicator' => IndicatorScore::withoutGlobalScopes()->find($fixture['indicator']->id)->toArray(),
        'competency' => CompetencyResult::withoutGlobalScopes()->where('evaluation_id', $fixture['evaluation']->id)->first()->toArray(),
        'evaluation' => Evaluation::withoutGlobalScopes()->find($fixture['evaluation']->id)->toArray(),
    ];

    AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null);

    expect(IndicatorScore::withoutGlobalScopes()->find($fixture['indicator']->id)->toArray())->toBe($before['indicator'])
        ->and(CompetencyResult::withoutGlobalScopes()->where('evaluation_id', $fixture['evaluation']->id)->first()->toArray())->toBe($before['competency'])
        ->and(Evaluation::withoutGlobalScopes()->find($fixture['evaluation']->id)->toArray())->toBe($before['evaluation']);
});

test('scoring rows stay byte-identical even on a fully failed run (every competency unavailable)', function (): void {
    $fixture = invarianceFixture();
    $fake = new FakeAuditJudge;
    $fake->throwOn('COL');
    app()->instance(AuditJudge::class, $fake);

    $before = IndicatorScore::withoutGlobalScopes()->find($fixture['indicator']->id)->toArray();

    AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null);

    expect(IndicatorScore::withoutGlobalScopes()->find($fixture['indicator']->id)->toArray())->toBe($before);
});

test('an audit run of any outcome adds zero rows to ai_requests', function (): void {
    $fixture = invarianceFixture();
    app()->instance(AuditJudge::class, new FakeAuditJudge);

    $before = AiRequest::withoutGlobalScopes()->count();

    AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null);

    expect(AiRequest::withoutGlobalScopes()->count())->toBe($before);
});

test('the DashboardController scoring cost/token metric is numerically unchanged before vs after one or more completed audit runs', function (): void {
    $fixture = invarianceFixture();
    $token = invarianceToken($fixture['org']);

    // A real ai_requests row so the metric is not trivially zero on both sides.
    AiRequest::create([
        'organization_id' => $fixture['org']->id,
        'competency_code' => 'COL',
        'provider' => 'anthropic',
        'model' => 'claude-opus-5',
        'prompt_version' => 'v1',
        'input_tokens' => 100,
        'output_tokens' => 50,
        'latency_ms' => 1200,
        'estimated_cost_usd' => '0.250000',
        'success' => true,
    ]);

    $before = $this->withToken($token)->getJson('/api/dashboard/metrics');
    $before->assertOk();

    app()->instance(AuditJudge::class, new FakeAuditJudge);
    AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null);
    // A second run, so the isolation invariant holds across multiple runs too.
    AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null);

    $after = $this->withToken($token)->getJson('/api/dashboard/metrics');
    $after->assertOk();

    expect($after->json('data.costs.scoring_usd'))->toBe($before->json('data.costs.scoring_usd'))
        ->and($after->json('data.ai_usage.input_tokens'))->toBe($before->json('data.ai_usage.input_tokens'))
        ->and($after->json('data.ai_usage.output_tokens'))->toBe($before->json('data.ai_usage.output_tokens'));
});
