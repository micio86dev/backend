<?php

declare(strict_types=1);

/**
 * RED — P3a.2/4/6/8/10/15/17: AuditEvaluationJob's happy-path state machine
 * (scoring-audit-jev, design D5/D6). Judge always succeeds in this slice —
 * FakeAuditJudge's default behaviour, mirroring the Suggested Work Units
 * table's own framing ("failure isolation is P3b"). Uses REAL `::dispatch()`
 * under the ambient `sync` connection (phpunit.xml) rather than calling
 * handle() directly, so Queue::before's ambient-context reset runs exactly
 * as it does in production (DeliverWebhookJobTest.php:329's own precedent).
 */

use App\Contracts\AuditJudge;
use App\Enums\Audit\AuditOutcomeReason;
use App\Enums\Audit\AuditRunStatus;
use App\Enums\Audit\AuditVerdictStatus;
use App\Jobs\AuditEvaluationJob;
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
use App\Testing\FakeAuditJudge;
use Illuminate\Support\Facades\Cache;

/**
 * Three competencies, one indicator each, exercising all three partition
 * branches: an unassessable (-1) indicator, a scored-but-no-excerpts
 * indicator, and a genuinely judgeable indicator.
 *
 * @return array{
 *     evaluation: Evaluation,
 *     unassessable: IndicatorScore,
 *     noExcerpts: IndicatorScore,
 *     judgeable: IndicatorScore,
 * }
 */
function auditJobFixture(): array
{
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    $comp1 = CompetencyResult::factory()->create(['evaluation_id' => $evaluation->id, 'competency_code' => 'COL']);
    $unassessable = IndicatorScore::factory()->unassessable()->create([
        'competency_result_id' => $comp1->id,
        'position' => 0,
    ]);

    $comp2 = CompetencyResult::factory()->create(['evaluation_id' => $evaluation->id, 'competency_code' => 'PRS']);
    $noExcerpts = IndicatorScore::factory()->create([
        'competency_result_id' => $comp2->id,
        'position' => 0,
        'score' => 4,
        'excerpts' => [],
    ]);

    $comp3 = CompetencyResult::factory()->create(['evaluation_id' => $evaluation->id, 'competency_code' => 'STG']);
    $judgeable = IndicatorScore::factory()->create([
        'competency_result_id' => $comp3->id,
        'position' => 0,
        'score' => 4,
        'excerpts' => ['A verbatim excerpt of candidate speech.'],
    ]);

    return [
        'evaluation' => $evaluation,
        'unassessable' => $unassessable,
        'noExcerpts' => $noExcerpts,
        'judgeable' => $judgeable,
    ];
}

function auditJobFakeJudge(): FakeAuditJudge
{
    $fake = new FakeAuditJudge(defaultSupportProbability: 0.75);
    app()->instance(AuditJudge::class, $fake);

    return $fake;
}

test('a score=-1 indicator is skipped as unassessable_by_construction and never reaches the judge', function (): void {
    $fixture = auditJobFixture();
    $fake = auditJobFakeJudge();

    AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null);

    $audit = IndicatorScoreAudit::withoutGlobalScopes()
        ->where('indicator_score_id', $fixture['unassessable']->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->status)->toBe(AuditVerdictStatus::Skipped)
        ->and($audit->outcome_reason)->toBe(AuditOutcomeReason::UnassessableByConstruction)
        ->and($audit->support_probability)->toBeNull();

    // Load-bearing: asserted on the FAKE's recorded calls, not on the output —
    // the -1 indicator's id must never appear in any subject sent to the judge.
    foreach ($fake->getCalls() as $call) {
        foreach ($call->subjects as $subject) {
            expect($subject->indicatorScoreId)->not->toBe($fixture['unassessable']->id);
        }
    }
});

test('a scored indicator with no excerpts is skipped with a distinct reason from the -1 case', function (): void {
    $fixture = auditJobFixture();
    auditJobFakeJudge();

    AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null);

    $audit = IndicatorScoreAudit::withoutGlobalScopes()
        ->where('indicator_score_id', $fixture['noExcerpts']->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->status)->toBe(AuditVerdictStatus::Skipped)
        ->and($audit->outcome_reason)->toBe(AuditOutcomeReason::AssessedWithoutExcerpts)
        ->and($audit->outcome_reason)->not->toBe(AuditOutcomeReason::UnassessableByConstruction);
});

test('an indicator with a score and excerpts is judged, with the composed probability and all three raws persisted', function (): void {
    $fixture = auditJobFixture();
    $fake = auditJobFakeJudge();

    AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null);

    $audit = IndicatorScoreAudit::withoutGlobalScopes()
        ->where('indicator_score_id', $fixture['judgeable']->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->status)->toBe(AuditVerdictStatus::Judged)
        ->and($audit->outcome_reason)->toBeNull()
        ->and((float) $audit->support_probability)->toBe(0.75)
        // toEqualCanonicalizing, not toBe: jsonb does not preserve object key
        // INSERTION order — Postgres stores jsonb keys sorted by (length,
        // byte value), so 'grounding' (9) sorts before 'relevance' (9)
        // before 'calibration' (11) on read back. The three VALUES are what
        // design D2 requires persisted, not their storage-layer key order.
        ->and($audit->question_probabilities)->toEqualCanonicalizing([
            'relevance' => 0.75,
            'calibration' => 0.75,
            'grounding' => 0.75,
        ]);

    $judgedCall = collect($fake->getCalls())->first(fn ($call) => $call->competencyCode === 'STG');
    expect($judgedCall)->not->toBeNull()
        ->and($judgedCall->subjects)->toHaveCount(1)
        ->and($judgedCall->subjects[0]->indicatorScoreId)->toBe($fixture['judgeable']->id);
});

test('a fully successful run records correct counters, aggregate tokens, latency, judge_model_version and prompt_version', function (): void {
    $fixture = auditJobFixture();
    auditJobFakeJudge();

    AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null);

    $run = IndicatorScoreAuditRun::withoutGlobalScopes()
        ->where('evaluation_id', $fixture['evaluation']->id)
        ->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(AuditRunStatus::Completed)
        ->and($run->failure_reason)->toBeNull()
        ->and($run->indicators_total)->toBe(3)
        ->and($run->indicators_judged)->toBe(1)
        ->and($run->indicators_skipped)->toBe(2)
        ->and($run->indicators_unavailable)->toBe(0)
        ->and($run->indicators_malformed)->toBe(0)
        ->and($run->input_tokens)->toBe(100)
        ->and($run->output_tokens)->toBe(50)
        ->and($run->latency_ms)->toBe(10)
        ->and($run->judge_model_version)->toBe('fake-jev-judge-v1')
        ->and($run->audit_prompt_version)->toBe(config('scoring.audit.prompt_version'));

    expect(IndicatorScoreAudit::withoutGlobalScopes()->where('audit_run_id', $run->id)->count())->toBe(3);
});

test('re-auditing an already-audited evaluation creates a new run; the earlier run and its audits are unchanged', function (): void {
    $fixture = auditJobFixture();
    auditJobFakeJudge();

    AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null);

    $run1 = IndicatorScoreAuditRun::withoutGlobalScopes()->where('evaluation_id', $fixture['evaluation']->id)->firstOrFail();
    $run1Snapshot = $run1->toArray();
    $run1AuditsSnapshot = IndicatorScoreAudit::withoutGlobalScopes()->where('audit_run_id', $run1->id)->get()->toArray();

    AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null);

    $runs = IndicatorScoreAuditRun::withoutGlobalScopes()->where('evaluation_id', $fixture['evaluation']->id)->get();

    expect($runs)->toHaveCount(2);

    $run1Fresh = IndicatorScoreAuditRun::withoutGlobalScopes()->find($run1->id);
    expect($run1Fresh->toArray())->toBe($run1Snapshot);
    expect(IndicatorScoreAudit::withoutGlobalScopes()->where('audit_run_id', $run1->id)->get()->toArray())
        ->toBe($run1AuditsSnapshot);
});

test('SCORING_AUDIT_ENABLED=false is re-checked inside the job — zero rows, zero judge calls, lock released, no throw', function (): void {
    $fixture = auditJobFixture();
    $fake = auditJobFakeJudge();

    config()->set('scoring.audit.enabled', false);

    $lockKey = "audit:evaluation:{$fixture['evaluation']->id}";
    $lock = Cache::lock($lockKey, 60);
    expect($lock->get())->toBeTrue();
    $owner = $lock->owner();
    $lock->release();

    $lock = Cache::lock($lockKey, 60, $owner);
    expect($lock->get())->toBeTrue();

    expect(fn () => AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, $owner))->not->toThrow(Throwable::class);

    expect(IndicatorScoreAuditRun::withoutGlobalScopes()->count())->toBe(0)
        ->and(IndicatorScoreAudit::withoutGlobalScopes()->count())->toBe(0)
        ->and($fake->callCount())->toBe(0);

    // The lock the job was handed must be released — re-acquiring it must succeed.
    expect(Cache::lock($lockKey, 60)->get())->toBeTrue();
});
