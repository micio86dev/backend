<?php

declare(strict_types=1);

/**
 * RED — P5.8/P5.10/P5.12: `AdminEvaluationSerializer`'s `behaviors[].audit`
 * behaviour (scoring-audit-jev design D9, spec.md "Evaluation Read Surface
 * Exposes Per-Indicator Audit Status").
 *
 * Confirmed GREEN on first run for the P5.8/P5.10 scenarios (the
 * `serializeAudit()`/`auditVerdicts()` wiring already exists from P5.5-P5.7,
 * written together as one GREEN pass — the same "confirmed GREEN on first
 * run" framing this batch's own P2.7/P3b.11 already used for a
 * non-literal RED→GREEN task). P5.12 (LATEST RUN semantics) is a genuine RED:
 * nothing before this test exercised a SECOND run superseding a first.
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

function auditSerializerFixture(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);
    $result = CompetencyResult::factory()->valid()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'COL',
    ]);
    $indicator = IndicatorScore::factory()->create(['competency_result_id' => $result->id]);

    return [$participant, $evaluation, $indicator];
}

test('a judged indicator serializes the verbatim verdict', function (): void {
    [$participant, $evaluation, $indicator] = auditSerializerFixture();

    $run = IndicatorScoreAuditRun::factory()->create(['evaluation_id' => $evaluation->id]);
    IndicatorScoreAudit::factory()->create([
        'audit_run_id' => $run->id,
        'indicator_score_id' => $indicator->id,
        'support_probability' => 0.8200,
    ]);

    $serialized = (new AdminEvaluationSerializer)->serialize($participant);

    expect($serialized['COL']['behaviors'][0]['audit'])->toBe([
        'status' => 'judged',
        'support_probability' => 0.82,
        'outcome_reason' => null,
    ]);
});

test('every behavior of a never-audited evaluation renders the never_audited status, never a missing key', function (): void {
    [$participant] = auditSerializerFixture();

    $serialized = (new AdminEvaluationSerializer)->serialize($participant);

    foreach ($serialized['COL']['behaviors'] as $behavior) {
        expect($behavior)->toHaveKey('audit')
            ->and($behavior['audit'])->toBe([
                'status' => 'never_audited',
                'support_probability' => null,
                'outcome_reason' => null,
            ]);
    }
});

test('pre-existing behaviors[] fields are unchanged in value/type/presence by this addition', function (): void {
    [$participant, $evaluation, $indicator] = auditSerializerFixture();

    $run = IndicatorScoreAuditRun::factory()->create(['evaluation_id' => $evaluation->id]);
    IndicatorScoreAudit::factory()->create([
        'audit_run_id' => $run->id,
        'indicator_score_id' => $indicator->id,
    ]);

    $behavior = (new AdminEvaluationSerializer)->serialize($participant)['COL']['behaviors'][0];

    expect($behavior['score'])->toBe($indicator->score === -1 ? null : $indicator->score)
        ->and($behavior['explanation'])->toBe($indicator->explanation)
        ->and($behavior['excerpts'])->toBe($indicator->excerpts)
        ->and($behavior['unassessable_reason'])->toBe($indicator->unassessable_reason)
        ->and($behavior['indicator'])->toBeString();
});

test('LATEST RUN semantics — a later run that skips an indicator the first run judged shows the later skip, not the earlier judgment', function (): void {
    [$participant, $evaluation, $indicator] = auditSerializerFixture();

    $firstRun = IndicatorScoreAuditRun::factory()->create(['evaluation_id' => $evaluation->id]);
    IndicatorScoreAudit::factory()->create([
        'audit_run_id' => $firstRun->id,
        'indicator_score_id' => $indicator->id,
        'support_probability' => 0.9000,
    ]);

    // A second, LATER run (created afterwards, so its created_at/id order
    // both this is intentionally the later one) that SKIPS the same
    // indicator — e.g. its excerpts were purged in the meantime.
    $secondRun = IndicatorScoreAuditRun::factory()->create(['evaluation_id' => $evaluation->id]);
    IndicatorScoreAudit::factory()->skipped()->create([
        'audit_run_id' => $secondRun->id,
        'indicator_score_id' => $indicator->id,
    ]);

    $serialized = (new AdminEvaluationSerializer)->serialize($participant);

    expect($serialized['COL']['behaviors'][0]['audit']['status'])->toBe('skipped')
        ->and($serialized['COL']['behaviors'][0]['audit']['support_probability'])->toBeNull();
});
