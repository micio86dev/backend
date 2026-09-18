<?php

declare(strict_types=1);

/**
 * RED — P2.9: both audit models' tenancy scoping (scoring-audit-jev design
 * D4, Multi-Tenancy table). `IndicatorScoreAuditRun`/`IndicatorScoreAudit`
 * extend `TenantModel`, so the invariants under test are the ones
 * `TenantScoped` provides structurally (P2.10 — no new production code):
 * `organization_id` absent from `$fillable`, the tamper-proof `creating`
 * stamp, and fail-closed with no tenant context. Mirrors
 * `TenantScopedTest.php`'s direct-attribute-assignment pattern (not a raw
 * DB insert — TenantScoped's stamp is an Eloquent model event, never fired
 * by `DB::table()->insert()`).
 */

use App\Exceptions\Tenancy\MissingTenantContextException;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\IndicatorScoreAudit;
use App\Models\IndicatorScoreAuditRun;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;

/**
 * @return array{0: Organization, 1: Evaluation}
 */
function auditTenancyFixture(): array
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

// ── organization_id absent from $fillable ──

test('organization_id is absent from IndicatorScoreAuditRun $fillable', function (): void {
    expect((new IndicatorScoreAuditRun)->getFillable())->not->toContain('organization_id');
});

test('organization_id is absent from IndicatorScoreAudit $fillable', function (): void {
    expect((new IndicatorScoreAudit)->getFillable())->not->toContain('organization_id');
});

// ── tamper-proof creating stamp: an attacker-supplied foreign org is overwritten ──

test('IndicatorScoreAuditRun creating listener overwrites a foreign organization_id (tamper-proof)', function (): void {
    [$org, $evaluation] = auditTenancyFixture();
    $foreignOrgId = $org->id + 999;

    $run = new IndicatorScoreAuditRun([
        'evaluation_id' => $evaluation->id,
        'status' => 'completed',
        'indicators_total' => 0,
        'indicators_judged' => 0,
        'indicators_skipped' => 0,
        'indicators_unavailable' => 0,
        'indicators_malformed' => 0,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'latency_ms' => 0,
        'judge_model_version' => 'jev-1',
        'audit_prompt_version' => '1.0.0',
    ]);
    $run->organization_id = $foreignOrgId; // attacker-supplied, bypassing $fillable

    $run->save();

    expect($run->organization_id)->toBe($org->id)
        ->and($run->organization_id)->not->toBe($foreignOrgId);
});

test('IndicatorScoreAudit creating listener overwrites a foreign organization_id (tamper-proof)', function (): void {
    [$org, $evaluation] = auditTenancyFixture();

    $run = IndicatorScoreAuditRun::factory()->create(['evaluation_id' => $evaluation->id]);
    $competencyResult = CompetencyResult::factory()->create(['evaluation_id' => $evaluation->id]);
    $indicatorScore = IndicatorScore::factory()->create(['competency_result_id' => $competencyResult->id]);
    $foreignOrgId = $org->id + 999;

    $audit = new IndicatorScoreAudit([
        'audit_run_id' => $run->id,
        'indicator_score_id' => $indicatorScore->id,
        'status' => 'judged',
        'support_probability' => 0.9,
        'outcome_reason' => null,
    ]);
    $audit->organization_id = $foreignOrgId;

    $audit->save();

    expect($audit->organization_id)->toBe($org->id)
        ->and($audit->organization_id)->not->toBe($foreignOrgId);
});

// ── fail-closed: no tenant context throws MissingTenantContextException ──

test('creating an IndicatorScoreAuditRun with no tenant context throws MissingTenantContextException', function (): void {
    [, $evaluation] = auditTenancyFixture();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(null);
    $resolver->setBypass(false);

    expect(fn () => IndicatorScoreAuditRun::create([
        'evaluation_id' => $evaluation->id,
        'status' => 'completed',
        'indicators_total' => 0,
        'indicators_judged' => 0,
        'indicators_skipped' => 0,
        'indicators_unavailable' => 0,
        'indicators_malformed' => 0,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'latency_ms' => 0,
        'judge_model_version' => 'jev-1',
        'audit_prompt_version' => '1.0.0',
    ]))->toThrow(MissingTenantContextException::class);
});

test('creating an IndicatorScoreAudit with no tenant context throws MissingTenantContextException', function (): void {
    [, $evaluation] = auditTenancyFixture();

    $run = IndicatorScoreAuditRun::factory()->create(['evaluation_id' => $evaluation->id]);
    $competencyResult = CompetencyResult::factory()->create(['evaluation_id' => $evaluation->id]);
    $indicatorScore = IndicatorScore::factory()->create(['competency_result_id' => $competencyResult->id]);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId(null);
    $resolver->setBypass(false);

    expect(fn () => IndicatorScoreAudit::create([
        'audit_run_id' => $run->id,
        'indicator_score_id' => $indicatorScore->id,
        'status' => 'judged',
        'support_probability' => 0.9,
        'outcome_reason' => null,
    ]))->toThrow(MissingTenantContextException::class);
});

// ── cross-tenant read isolation ──

test('an org B ambient context sees zero rows from org A audit tables', function (): void {
    [$orgA, $evaluationA] = auditTenancyFixture();
    IndicatorScoreAuditRun::factory()->create(['evaluation_id' => $evaluationA->id]);

    $orgB = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgB->id);
    $resolver->setBypass(false);

    expect(IndicatorScoreAuditRun::count())->toBe(0);

    $resolver->setOrgId($orgA->id);
    expect(IndicatorScoreAuditRun::count())->toBe(1);
});
