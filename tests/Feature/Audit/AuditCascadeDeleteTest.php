<?php

declare(strict_types=1);

/**
 * RED — P2.11: cascade-delete from BOTH parents (scoring-audit-jev design
 * D4). `cascadeOnDelete` from both `indicator_scores` and
 * `indicator_score_audit_runs` is deliberate: an audit is deleted when its
 * subject indicator is purged (AD-2's data-retention requirement) AND when
 * its owning run is deleted, so no dangling row of either kind is ever
 * reachable.
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
use App\Support\Tenancy\TenantResolver;

/**
 * @return array{0: IndicatorScoreAuditRun, 1: IndicatorScore, 2: IndicatorScoreAudit}
 */
function auditCascadeFixture(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    $run = IndicatorScoreAuditRun::factory()->create(['evaluation_id' => $evaluation->id]);
    $competencyResult = CompetencyResult::factory()->create(['evaluation_id' => $evaluation->id]);
    $indicatorScore = IndicatorScore::factory()->create(['competency_result_id' => $competencyResult->id]);
    $audit = IndicatorScoreAudit::factory()->create([
        'audit_run_id' => $run->id,
        'indicator_score_id' => $indicatorScore->id,
    ]);

    return [$run, $indicatorScore, $audit];
}

test('both models expose the D4/P2.5 relations correctly', function (): void {
    [$run, $indicatorScore, $audit] = auditCascadeFixture();

    expect($run->audits()->first()->id)->toBe($audit->id)
        ->and($run->evaluation)->not->toBeNull()
        ->and($audit->auditRun->id)->toBe($run->id)
        ->and($audit->indicatorScore->id)->toBe($indicatorScore->id);
});

test('deleting the IndicatorScore cascades to its indicator_score_audits rows', function (): void {
    [, $indicatorScore, $audit] = auditCascadeFixture();

    expect(IndicatorScoreAudit::find($audit->id))->not->toBeNull();

    $indicatorScore->delete();

    expect(IndicatorScoreAudit::find($audit->id))->toBeNull()
        ->and(IndicatorScoreAudit::count())->toBe(0);
});

test('deleting the owning IndicatorScoreAuditRun cascades to its indicator_score_audits rows', function (): void {
    [$run, , $audit] = auditCascadeFixture();

    expect(IndicatorScoreAudit::find($audit->id))->not->toBeNull();

    $run->delete();

    expect(IndicatorScoreAudit::find($audit->id))->toBeNull()
        ->and(IndicatorScoreAuditRun::find($run->id))->toBeNull()
        ->and(IndicatorScoreAudit::count())->toBe(0);
});

test('no dangling row of either kind survives either delete path', function (): void {
    [$run1, $indicatorScore1, $audit1] = auditCascadeFixture();

    // A second, independent audit row on a DIFFERENT run/indicator must
    // survive both deletes above untouched.
    $run2 = IndicatorScoreAuditRun::factory()->create(['evaluation_id' => $run1->evaluation_id]);
    // A competency_code DISTINCT from fixture 1's random pick — same
    // evaluation_id — competency_results has a UNIQUE(evaluation_id,
    // competency_code) constraint the faker default can otherwise collide on.
    $competencyResult2 = CompetencyResult::factory()->create([
        'evaluation_id' => $run1->evaluation_id,
        'competency_code' => 'INS',
    ]);
    $indicatorScore2 = IndicatorScore::factory()->create(['competency_result_id' => $competencyResult2->id]);
    $audit2 = IndicatorScoreAudit::factory()->create([
        'audit_run_id' => $run2->id,
        'indicator_score_id' => $indicatorScore2->id,
    ]);

    $indicatorScore1->delete();
    $run1->delete();

    expect(IndicatorScoreAudit::find($audit1->id))->toBeNull()
        ->and(IndicatorScoreAudit::find($audit2->id))->not->toBeNull()
        ->and(IndicatorScoreAuditRun::find($run2->id))->not->toBeNull()
        ->and(IndicatorScore::find($indicatorScore2->id))->not->toBeNull()
        ->and(IndicatorScoreAudit::count())->toBe(1);
});
