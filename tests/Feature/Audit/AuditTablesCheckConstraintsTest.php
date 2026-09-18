<?php

declare(strict_types=1);

/**
 * RED — P2.7: raw-insert CHECK-constraint violations on both audit tables
 * (scoring-audit-jev design D4/C-D/C-E). A CHECK is not testable through
 * Eloquent — an Eloquent `create()` would never even attempt an illegal
 * combination — so every scenario here goes through `DB::table(...)->insert()`
 * directly, mirroring `UtteranceTurnKindCheckTest.php` /
 * `WebhookDeliveriesMigrationTest.php`'s own established pattern, and asserts
 * the EXACT named constraint via the shared `assertPostgresConstraintViolation()`
 * helper (never a bare `QueryException` class assertion).
 *
 * Note on AD-6's "no free text, no copied excerpt" adversarial concern: this
 * is structural at this layer, not something to additionally assert here —
 * neither table has a text/string column capable of holding free-form judge
 * output or a copied excerpt (see the migration schema tests for the full
 * column list).
 */

use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\IndicatorScoreAuditRun;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;

/**
 * Builds a real, tenant-scoped Evaluation — mirrors
 * `CompetencyResultIndicatorScoresAggregateTest.php`'s `z11CompetencyResult()`
 * fixture chain: `Participant` is NOT a `TenantModel` and derives
 * `organization_id` from its `Project` via `forProject()`, so `Evaluation::factory()`'s
 * own default `Participant::factory()` (no project) cannot be used directly.
 *
 * Sets the TenantResolver as a side effect — callers create everything else
 * under the SAME org this returns.
 */
function auditTenantEvaluation(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    return [$org, $evaluation];
}

/**
 * @return array{0: Organization, 1: IndicatorScoreAuditRun, 2: IndicatorScore}
 */
function auditCheckConstraintFixtures(): array
{
    [$org, $evaluation] = auditTenantEvaluation();

    $run = IndicatorScoreAuditRun::factory()->create(['evaluation_id' => $evaluation->id]);

    $competencyResult = CompetencyResult::factory()->create(['evaluation_id' => $evaluation->id]);
    $indicatorScore = IndicatorScore::factory()->create(['competency_result_id' => $competencyResult->id]);

    return [$org, $run, $indicatorScore];
}

/**
 * @return array<string, mixed>
 */
function auditBaseIndicatorRow(Organization $org, IndicatorScoreAuditRun $run, IndicatorScore $indicatorScore): array
{
    return [
        'organization_id' => $org->id,
        'audit_run_id' => $run->id,
        'indicator_score_id' => $indicatorScore->id,
        'status' => 'judged',
        'support_probability' => 0.9,
        'question_probabilities' => json_encode(['relevance' => 0.9, 'calibration' => 0.9, 'grounding' => 0.9], JSON_THROW_ON_ERROR),
        'outcome_reason' => null,
        'created_at' => now(),
    ];
}

/**
 * @return array<string, mixed>
 */
function auditBaseRunRow(Organization $org, Evaluation $evaluation): array
{
    return [
        'organization_id' => $org->id,
        'evaluation_id' => $evaluation->id,
        'requested_by_user_id' => null,
        'status' => 'completed',
        'failure_reason' => null,
        'indicators_total' => 10,
        'indicators_judged' => 8,
        'indicators_skipped' => 2,
        'indicators_unavailable' => 0,
        'indicators_malformed' => 0,
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'estimated_cost_usd' => 0.01,
        'latency_ms' => 5000,
        'judge_model_version' => 'jev-1',
        'audit_prompt_version' => '1.0.0',
        'created_at' => now(),
    ];
}

test('a legal judged indicator row insert succeeds', function (): void {
    [$org, $run, $indicatorScore] = auditCheckConstraintFixtures();

    DB::table('indicator_score_audits')->insert(auditBaseIndicatorRow($org, $run, $indicatorScore));

    expect(DB::table('indicator_score_audits')->count())->toBe(1);
});

test('CHECK rejects status=judged with support_probability NULL', function (): void {
    [$org, $run, $indicatorScore] = auditCheckConstraintFixtures();

    $row = auditBaseIndicatorRow($org, $run, $indicatorScore);
    $row['support_probability'] = null;

    assertPostgresConstraintViolation(
        fn () => DB::table('indicator_score_audits')->insert($row),
        '23514',
        'indicator_score_audits_probability_check',
    );
});

test('CHECK rejects status=skipped with a non-null support_probability', function (): void {
    [$org, $run, $indicatorScore] = auditCheckConstraintFixtures();

    $row = auditBaseIndicatorRow($org, $run, $indicatorScore);
    $row['status'] = 'skipped';
    $row['support_probability'] = 0.9;
    $row['outcome_reason'] = 'unassessable_by_construction';

    assertPostgresConstraintViolation(
        fn () => DB::table('indicator_score_audits')->insert($row),
        '23514',
        'indicator_score_audits_probability_check',
    );
});

test('CHECK rejects status=judged with a non-null outcome_reason', function (): void {
    [$org, $run, $indicatorScore] = auditCheckConstraintFixtures();

    $row = auditBaseIndicatorRow($org, $run, $indicatorScore);
    $row['outcome_reason'] = 'assessed_without_excerpts';

    assertPostgresConstraintViolation(
        fn () => DB::table('indicator_score_audits')->insert($row),
        '23514',
        'indicator_score_audits_reason_check',
    );
});

test('CHECK rejects a probability outside [0, 1] (1.5)', function (): void {
    [$org, $run, $indicatorScore] = auditCheckConstraintFixtures();

    $row = auditBaseIndicatorRow($org, $run, $indicatorScore);
    $row['support_probability'] = 1.5;

    assertPostgresConstraintViolation(
        fn () => DB::table('indicator_score_audits')->insert($row),
        '23514',
        'indicator_score_audits_probability_domain_check',
    );
});

test('CHECK rejects an unrecognised status on indicator_score_audits', function (): void {
    [$org, $run, $indicatorScore] = auditCheckConstraintFixtures();

    $row = auditBaseIndicatorRow($org, $run, $indicatorScore);
    $row['status'] = 'bogus';
    // Satisfy the OTHER three CHECKs (probability/reason/domain equivalences
    // all treat 'bogus' as "not judged") so only status_check is isolated.
    $row['support_probability'] = null;
    $row['outcome_reason'] = 'irrelevant';

    assertPostgresConstraintViolation(
        fn () => DB::table('indicator_score_audits')->insert($row),
        '23514',
        'indicator_score_audits_status_check',
    );
});

test('a legal completed run row insert succeeds', function (): void {
    [$org, $evaluation] = auditTenantEvaluation();

    DB::table('indicator_score_audit_runs')->insert(auditBaseRunRow($org, $evaluation));

    expect(DB::table('indicator_score_audit_runs')->count())->toBe(1);
});

test('CHECK rejects a run row whose four counters do not sum to indicators_total (C-D)', function (): void {
    [$org, $evaluation] = auditTenantEvaluation();

    $row = auditBaseRunRow($org, $evaluation);
    $row['indicators_total'] = 100; // does not equal 8 + 2 + 0 + 0

    assertPostgresConstraintViolation(
        fn () => DB::table('indicator_score_audit_runs')->insert($row),
        '23514',
        'indicator_score_audit_runs_coverage_check',
    );
});

test('CHECK rejects an unrecognised status on indicator_score_audit_runs', function (): void {
    [$org, $evaluation] = auditTenantEvaluation();

    $row = auditBaseRunRow($org, $evaluation);
    $row['status'] = 'bogus';
    $row['failure_reason'] = 'irrelevant'; // avoid tripping the failure_reason check instead

    assertPostgresConstraintViolation(
        fn () => DB::table('indicator_score_audit_runs')->insert($row),
        '23514',
        'indicator_score_audit_runs_status_check',
    );
});
