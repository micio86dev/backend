<?php

declare(strict_types=1);

/**
 * RED — P5.14/P5.16: `AdminEvaluationSerializer::auditMeta()`,
 * `EvaluationResource`'s `meta.audit` sibling, and the AD-7/D9
 * byte-identical invariant between the full report and the session-review
 * view (scoring-audit-jev design D9).
 */

use App\Http\Resources\Admin\EvaluationResource;
use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\IndicatorScoreAudit;
use App\Models\IndicatorScoreAuditRun;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Services\Admin\AdminEvaluationSerializer;
use App\Support\Admin\SessionEvidenceReader;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\Request;

test('auditMeta() returns null for a never-audited evaluation', function (): void {
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    expect((new AdminEvaluationSerializer)->auditMeta($participant))->toBeNull();
});

test('auditMeta() returns the latest run counters and versions', function (): void {
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);
    $run = IndicatorScoreAuditRun::factory()->create(['evaluation_id' => $evaluation->id]);

    $meta = (new AdminEvaluationSerializer)->auditMeta($participant);

    expect($meta)->not->toBeNull()
        ->and($meta['run_id'])->toBe($run->id)
        ->and($meta['status'])->toBe($run->status->value)
        ->and($meta['judge_model_version'])->toBe($run->judge_model_version)
        ->and($meta['audit_prompt_version'])->toBe($run->audit_prompt_version)
        ->and($meta['indicators_total'])->toBe($run->indicators_total)
        ->and($meta['indicators_judged'])->toBe($run->indicators_judged)
        ->and($meta['indicators_skipped'])->toBe($run->indicators_skipped)
        ->and($meta['indicators_unavailable'])->toBe($run->indicators_unavailable)
        ->and($meta['indicators_malformed'])->toBe($run->indicators_malformed)
        ->and($meta['created_at'])->toBeString();
});

test('EvaluationResource emits meta.audit as a sibling of meta.scoring, and null when never audited', function (): void {
    $participant = new Participant;
    $request = Request::create('/');

    $resourceWithAudit = new EvaluationResource(
        [],
        ['prompt_version' => 'v1', 'model_version' => 'm1', 'framework_version' => 'f1'],
        ['run_id' => 1, 'status' => 'completed', 'judge_model_version' => 'jev-1', 'audit_prompt_version' => '1.0.0', 'created_at' => 'x', 'indicators_total' => 1, 'indicators_judged' => 1, 'indicators_skipped' => 0, 'indicators_unavailable' => 0, 'indicators_malformed' => 0],
    );
    expect($resourceWithAudit->with($request))->toBe([
        'meta' => [
            'scoring' => ['prompt_version' => 'v1', 'model_version' => 'm1', 'framework_version' => 'f1'],
            'audit' => ['run_id' => 1, 'status' => 'completed', 'judge_model_version' => 'jev-1', 'audit_prompt_version' => '1.0.0', 'created_at' => 'x', 'indicators_total' => 1, 'indicators_judged' => 1, 'indicators_skipped' => 0, 'indicators_unavailable' => 0, 'indicators_malformed' => 0],
        ],
    ]);

    $neverAuditedResource = new EvaluationResource(
        [],
        ['prompt_version' => 'v1', 'model_version' => 'm1', 'framework_version' => 'f1'],
        null,
    );
    expect($neverAuditedResource->with($request))->toBe([
        'meta' => [
            'scoring' => ['prompt_version' => 'v1', 'model_version' => 'm1', 'framework_version' => 'f1'],
            'audit' => null,
        ],
    ]);
});

test('the full report and the session-review view emit a byte-identical audit object for the same indicator', function (): void {
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $competency = Competency::factory()->create(['code' => 'COL']);
    $project->competencies()->attach($competency->id, ['position' => 0]);

    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);
    $result = CompetencyResult::factory()->valid()->create([
        'evaluation_id' => $evaluation->id,
        'competency_code' => 'COL',
    ]);
    $indicator = IndicatorScore::factory()->create(['competency_result_id' => $result->id]);

    $run = IndicatorScoreAuditRun::factory()->create(['evaluation_id' => $evaluation->id]);
    IndicatorScoreAudit::factory()->create([
        'audit_run_id' => $run->id,
        'indicator_score_id' => $indicator->id,
        'support_probability' => 0.7500,
    ]);

    $session = InterviewSession::factory()->create([
        'organization_id' => $org->id,
        'participant_id' => $participant->id,
        'project_id' => $project->id,
        'framework_version_id' => $fv->id,
        'competency_code' => 'COL',
        'provider' => 'heygen',
    ]);

    $fromFullReport = (new AdminEvaluationSerializer)->serialize($participant)['COL']['behaviors'][0]['audit'];
    $fromSessionReview = app(SessionEvidenceReader::class)->forSession($session, $participant->status)['behaviors'][0]['audit'];

    expect($fromSessionReview)->toBe($fromFullReport);
});
