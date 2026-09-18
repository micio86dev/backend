<?php

declare(strict_types=1);

/**
 * RED — P3b.5-P3b.10: a batched judge call that succeeds (does not throw)
 * but whose response omits or corrupts one indicator's verdict is the
 * distinct `malformed` case (scoring-audit-jev, design C-C/C-E, spec "A
 * Per-Subject Malformed Verdict Is Distinct From A Competency-Level
 * Unavailable Outcome"). A competency's overall call succeeding MUST NOT
 * force every one of its indicators to `judged` — each indicator's own
 * verdict presence/validity is checked independently.
 */

use App\Contracts\AuditJudge;
use App\DTOs\Audit\AuditBatchResult;
use App\DTOs\Audit\AuditRequest;
use App\DTOs\Audit\AuditVerdict;
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

/**
 * A single competency (COL) with two judgeable indicators — one that will
 * receive a valid verdict, one whose verdict is engineered to be omitted.
 *
 * @return array{evaluation: Evaluation, present: IndicatorScore, missing: IndicatorScore}
 */
function malformedVerdictFixture(): array
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

    $present = IndicatorScore::factory()->create([
        'competency_result_id' => $comp->id,
        'position' => 0,
        'score' => 4,
        'excerpts' => ['A verbatim excerpt.'],
    ]);
    $missing = IndicatorScore::factory()->create([
        'competency_result_id' => $comp->id,
        'position' => 1,
        'score' => 3,
        'excerpts' => ['Another verbatim excerpt.'],
    ]);

    return ['evaluation' => $evaluation, 'present' => $present, 'missing' => $missing];
}

/**
 * A stub `AuditJudge` that returns a verdict for ONE subject only, with a
 * caller-supplied `$omissions` map for every other subject it was sent —
 * asserts the job's own consumption of `AuditBatchResult::$omissions`
 * independently from `JevResponseMapper`'s production of it (already
 * unit-tested in `JevResponseMapperTest`).
 */
function omittingJudge(int $presentId, int $missingId, AuditOutcomeReason $reason): AuditJudge
{
    return new class($presentId, $missingId, $reason) implements AuditJudge
    {
        public function __construct(
            private readonly int $presentId,
            private readonly int $missingId,
            private readonly AuditOutcomeReason $reason,
        ) {}

        public function judge(AuditRequest $request): AuditBatchResult
        {
            return new AuditBatchResult(
                verdicts: [
                    $this->presentId => new AuditVerdict(
                        indicatorScoreId: $this->presentId,
                        supportProbability: 0.9,
                        questionProbabilities: ['relevance' => 0.9, 'calibration' => 0.9, 'grounding' => 0.9],
                    ),
                ],
                omissions: [$this->missingId => $this->reason],
                inputTokens: 100,
                outputTokens: 50,
                judgeModel: 'stub-jev',
                latencyMs: 5,
            );
        }
    };
}

test('a missing per-subject verdict from an otherwise-successful call is malformed/verdict_missing, siblings still judged', function (): void {
    $fixture = malformedVerdictFixture();
    app()->instance(AuditJudge::class, omittingJudge($fixture['present']->id, $fixture['missing']->id, AuditOutcomeReason::VerdictMissing));

    AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null);

    $missingAudit = IndicatorScoreAudit::withoutGlobalScopes()->where('indicator_score_id', $fixture['missing']->id)->first();
    expect($missingAudit)->not->toBeNull()
        ->and($missingAudit->status)->toBe(AuditVerdictStatus::Malformed)
        ->and($missingAudit->outcome_reason)->toBe(AuditOutcomeReason::VerdictMissing)
        ->and($missingAudit->support_probability)->toBeNull();

    $presentAudit = IndicatorScoreAudit::withoutGlobalScopes()->where('indicator_score_id', $fixture['present']->id)->first();
    expect($presentAudit)->not->toBeNull()
        ->and($presentAudit->status)->toBe(AuditVerdictStatus::Judged);
});

test('a non-numeric/out-of-domain probability omission is malformed with its own reason — never judged, never unavailable', function (): void {
    $fixture = malformedVerdictFixture();
    app()->instance(AuditJudge::class, omittingJudge($fixture['present']->id, $fixture['missing']->id, AuditOutcomeReason::ProbabilityOutOfDomain));

    AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null);

    $missingAudit = IndicatorScoreAudit::withoutGlobalScopes()->where('indicator_score_id', $fixture['missing']->id)->first();
    expect($missingAudit->status)->toBe(AuditVerdictStatus::Malformed)
        ->and($missingAudit->outcome_reason)->toBe(AuditOutcomeReason::ProbabilityOutOfDomain)
        ->and($missingAudit->status)->not->toBe(AuditVerdictStatus::Judged)
        ->and($missingAudit->status)->not->toBe(AuditVerdictStatus::Unavailable);
});

test('a malformed verdict increments indicators_malformed only, never indicators_unavailable, when no competency call threw', function (): void {
    $fixture = malformedVerdictFixture();
    app()->instance(AuditJudge::class, omittingJudge($fixture['present']->id, $fixture['missing']->id, AuditOutcomeReason::VerdictUnparseable));

    AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null);

    $run = IndicatorScoreAuditRun::withoutGlobalScopes()->where('evaluation_id', $fixture['evaluation']->id)->firstOrFail();

    expect($run->indicators_malformed)->toBe(1)
        ->and($run->indicators_unavailable)->toBe(0)
        ->and($run->indicators_judged)->toBe(1)
        ->and($run->indicators_total)->toBe(2);
});

test('a run where every judgeable indicator ends up malformed (no competency call threw) is NOT recorded completed', function (): void {
    // RED — P4 review finding #2: `AuditRunStatus::Completed`'s own docblock
    // says "No competency's judge call threw, AND no verdict was malformed."
    // The prior derivation set `$runStatus = completed` whenever no throw
    // occurred, regardless of how many verdicts were malformed — a run with
    // zero throws and 100% malformed verdicts was recorded `completed`,
    // contradicting the enum's own contract.
    $fixture = malformedVerdictFixture();

    app()->instance(AuditJudge::class, new class($fixture['present']->id, $fixture['missing']->id) implements AuditJudge
    {
        public function __construct(private readonly int $presentId, private readonly int $missingId) {}

        public function judge(AuditRequest $request): AuditBatchResult
        {
            // Both subjects omitted — the call itself does not throw, but
            // NOTHING in this competency's response is usable.
            return new AuditBatchResult(
                verdicts: [],
                omissions: [
                    $this->presentId => AuditOutcomeReason::VerdictMissing,
                    $this->missingId => AuditOutcomeReason::VerdictMissing,
                ],
                inputTokens: 10,
                outputTokens: 5,
                judgeModel: 'stub-jev',
                latencyMs: 1,
            );
        }
    });

    AuditEvaluationJob::dispatch($fixture['evaluation']->id, null, null);

    $run = IndicatorScoreAuditRun::withoutGlobalScopes()->where('evaluation_id', $fixture['evaluation']->id)->firstOrFail();

    expect($run->status)->not->toBe(AuditRunStatus::Completed)
        ->and($run->status)->toBe(AuditRunStatus::Failed)
        ->and($run->indicators_judged)->toBe(0)
        ->and($run->indicators_malformed)->toBe(2)
        ->and($run->failure_reason)->not->toBeNull();
});
