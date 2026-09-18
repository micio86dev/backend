<?php

declare(strict_types=1);

/**
 * RED — P5.6: `AdminEvaluationSerializer::auditVerdicts()` (scoring-audit-jev
 * design D9) resolves the latest run ONCE and loads its verdicts in EXACTLY
 * 2 queries for the whole report — mirroring `indicatorCatalogue()`'s own
 * "one query for the whole report" doctrine (avoiding an N+1 across
 * competencies), asserted via query count, not just correctness.
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
use App\Services\Admin\AdminEvaluationSerializer;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;

test('auditVerdicts() resolves in exactly 2 queries for the whole report, regardless of competency count', function (): void {
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    $run = IndicatorScoreAuditRun::factory()->create(['evaluation_id' => $evaluation->id]);

    // THREE competencies, each with indicators covered by the run — an N+1
    // implementation would issue verdictsForRun-shaped queries per
    // competency; the correct one issues exactly 2, total, for the report.
    foreach (['COL', 'INS', 'DRV'] as $code) {
        $result = CompetencyResult::factory()->create([
            'evaluation_id' => $evaluation->id,
            'competency_code' => $code,
        ]);
        $indicator = IndicatorScore::factory()->create(['competency_result_id' => $result->id]);
        IndicatorScoreAudit::factory()->create([
            'audit_run_id' => $run->id,
            'indicator_score_id' => $indicator->id,
        ]);
    }

    $serializer = new AdminEvaluationSerializer;
    $method = new ReflectionMethod($serializer, 'auditVerdicts');
    $method->setAccessible(true);

    DB::enableQueryLog();
    $verdicts = $method->invoke($serializer, $evaluation->id);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($verdicts)->toHaveCount(3)
        ->and($queryCount)->toBe(2, 'auditVerdicts() must resolve the whole report in exactly 2 queries (latestRunFor + verdictsForRun), never one per competency.');
});

test('auditVerdicts() resolves in exactly 1 query when the evaluation has never been audited', function (): void {
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    $serializer = new AdminEvaluationSerializer;
    $method = new ReflectionMethod($serializer, 'auditVerdicts');
    $method->setAccessible(true);

    DB::enableQueryLog();
    $verdicts = $method->invoke($serializer, $evaluation->id);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($verdicts)->toBe([])
        ->and($queryCount)->toBe(1, 'verdictsForRun() must never be called when latestRunFor() already found nothing.');
});
