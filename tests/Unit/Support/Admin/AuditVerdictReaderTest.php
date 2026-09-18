<?php

declare(strict_types=1);

/**
 * RED — P5.1: AuditVerdictReader (scoring-audit-jev design D10).
 *
 * `AuditVerdictReader` is the ONLY class in `app/` permitted to query either
 * audit table — what lets `AuditAppendOnlyArchTest` ban `::where(`/`::find(`
 * everywhere else outright. Both queries run under the ambient tenant scope
 * (both models extend `TenantModel`, C2) — never `withoutGlobalScopes()`,
 * `AdminEvaluationSerializer`'s own docblock rule.
 */

use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\IndicatorScoreAudit;
use App\Models\IndicatorScoreAuditRun;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Admin\AuditVerdictReader;
use App\Support\Tenancy\TenantResolver;

/**
 * Same fixture shape as AuditCascadeDeleteTest.php's auditCascadeFixture() —
 * an org-scoped Evaluation ready to own audit runs.
 */
function auditReaderEvaluation(?Organization $org = null): Evaluation
{
    $org ??= Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();

    return Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);
}

test('latestRunFor returns null when the evaluation has never been audited', function (): void {
    $evaluation = auditReaderEvaluation();

    $reader = new AuditVerdictReader;

    expect($reader->latestRunFor($evaluation->id))->toBeNull();
});

test('latestRunFor returns the most recently created run, not the first', function (): void {
    $evaluation = auditReaderEvaluation();

    $first = IndicatorScoreAuditRun::factory()->create(['evaluation_id' => $evaluation->id]);
    $second = IndicatorScoreAuditRun::factory()->create(['evaluation_id' => $evaluation->id]);

    $reader = new AuditVerdictReader;
    $latest = $reader->latestRunFor($evaluation->id);

    expect($latest)->not->toBeNull()
        ->and($latest->id)->toBe($second->id)
        ->and($latest->id)->not->toBe($first->id);
});

test('latestRunFor scopes to the ambient tenant — a foreign-org evaluation resolves nothing', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $evaluationInOrgB = auditReaderEvaluation($orgB);
    IndicatorScoreAuditRun::factory()->create(['evaluation_id' => $evaluationInOrgB->id]);

    // Switch the ambient resolver to orgA — orgB's run must be invisible.
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);

    $reader = new AuditVerdictReader;

    expect($reader->latestRunFor($evaluationInOrgB->id))->toBeNull();
});

test('verdictsForRun keys every verdict by indicator_score_id', function (): void {
    $evaluation = auditReaderEvaluation();
    $run = IndicatorScoreAuditRun::factory()->create(['evaluation_id' => $evaluation->id]);

    $competencyResult = CompetencyResult::factory()->create(['evaluation_id' => $evaluation->id]);
    $indicatorA = IndicatorScore::factory()->create(['competency_result_id' => $competencyResult->id]);
    $indicatorB = IndicatorScore::factory()->create(['competency_result_id' => $competencyResult->id]);

    $auditA = IndicatorScoreAudit::factory()->create([
        'audit_run_id' => $run->id,
        'indicator_score_id' => $indicatorA->id,
    ]);
    $auditB = IndicatorScoreAudit::factory()->skipped()->create([
        'audit_run_id' => $run->id,
        'indicator_score_id' => $indicatorB->id,
    ]);

    $reader = new AuditVerdictReader;
    $verdicts = $reader->verdictsForRun($run->id);

    expect($verdicts)->toHaveCount(2)
        ->and($verdicts[$indicatorA->id]->id)->toBe($auditA->id)
        ->and($verdicts[$indicatorB->id]->id)->toBe($auditB->id);
});

test('verdictsForRun scopes to the ambient tenant — a foreign-org run resolves nothing', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $evaluationInOrgB = auditReaderEvaluation($orgB);
    $runInOrgB = IndicatorScoreAuditRun::factory()->create(['evaluation_id' => $evaluationInOrgB->id]);
    $competencyResult = CompetencyResult::factory()->create(['evaluation_id' => $evaluationInOrgB->id]);
    $indicatorScore = IndicatorScore::factory()->create(['competency_result_id' => $competencyResult->id]);
    IndicatorScoreAudit::factory()->create([
        'audit_run_id' => $runInOrgB->id,
        'indicator_score_id' => $indicatorScore->id,
    ]);

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($orgA->id);
    $resolver->setBypass(false);

    $reader = new AuditVerdictReader;

    expect($reader->verdictsForRun($runInOrgB->id))->toBe([]);
});

test('hasRunInFlight always returns false — no audit table ever persists an in-progress state (D7)', function (): void {
    $evaluation = auditReaderEvaluation();

    $reader = new AuditVerdictReader;

    // Even immediately "after dispatch" (simulated here by there being no
    // run row at all yet, exactly the real timing window) the reader has
    // nothing to read that would say "one is running" — design D7 rejected
    // a `running` row precisely because it would force an UPDATE on a table
    // this change arch-tests as append-only.
    expect($reader->hasRunInFlight($evaluation->id))->toBeFalse();

    IndicatorScoreAuditRun::factory()->create(['evaluation_id' => $evaluation->id]);

    expect($reader->hasRunInFlight($evaluation->id))->toBeFalse();
});

test('hasRunInFlight is never referenced by EvaluationAuditController — the Redis lock is the sole in-flight mechanism (D7)', function (): void {
    $source = (string) file_get_contents(app_path('Http/Controllers/Api/EvaluationAuditController.php'));

    expect($source)->not->toContain('hasRunInFlight');
});
