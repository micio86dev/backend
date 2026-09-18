<?php

declare(strict_types=1);

/**
 * RED — P3b.11-P3b.12: the four-term coverage identity (`total = judged +
 * skipped + unavailable + malformed`, design C-D) holds on a run mixing all
 * four outcomes in one evaluation — the spec's three worked scenarios
 * (fully successful; partially failed; with malformed verdicts) combined
 * into one run, plus the database CHECK itself as the final backstop.
 */

use App\Contracts\AuditJudge;
use App\DTOs\Audit\AuditBatchResult;
use App\DTOs\Audit\AuditRequest;
use App\DTOs\Audit\AuditVerdict;
use App\Enums\Audit\AuditOutcomeReason;
use App\Exceptions\Audit\AuditJudgeException;
use App\Jobs\AuditEvaluationJob;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\FrameworkVersion;
use App\Models\IndicatorScore;
use App\Models\IndicatorScoreAuditRun;
use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('the four-term coverage identity holds on a run mixing judged, skipped, unavailable, and malformed outcomes', function (): void {
    $org = Organization::factory()->create();

    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    // COMP-A: one judged, one skipped (no excerpts).
    $compA = CompetencyResult::factory()->create(['evaluation_id' => $evaluation->id, 'competency_code' => 'COL']);
    $judgedIndicator = IndicatorScore::factory()->create([
        'competency_result_id' => $compA->id, 'position' => 0, 'score' => 4, 'excerpts' => ['e1'],
    ]);
    $skippedIndicator = IndicatorScore::factory()->create([
        'competency_result_id' => $compA->id, 'position' => 1, 'score' => 4, 'excerpts' => [],
    ]);

    // COMP-B: throws entirely — its one indicator becomes unavailable.
    $compB = CompetencyResult::factory()->create(['evaluation_id' => $evaluation->id, 'competency_code' => 'PRS']);
    $unavailableIndicator = IndicatorScore::factory()->create([
        'competency_result_id' => $compB->id, 'position' => 0, 'score' => 3, 'excerpts' => ['e2'],
    ]);

    // COMP-C: succeeds but omits its one indicator's verdict — malformed.
    $compC = CompetencyResult::factory()->create(['evaluation_id' => $evaluation->id, 'competency_code' => 'STG']);
    $malformedIndicator = IndicatorScore::factory()->create([
        'competency_result_id' => $compC->id, 'position' => 0, 'score' => 5, 'excerpts' => ['e3'],
    ]);

    $judge = new class($judgedIndicator->id, $malformedIndicator->id) implements AuditJudge
    {
        public function __construct(private readonly int $judgedId, private readonly int $malformedId) {}

        public function judge(AuditRequest $request): AuditBatchResult
        {
            if ($request->competencyCode === 'PRS') {
                throw new AuditJudgeException('Simulated vendor outage.', retryable: true);
            }

            if ($request->competencyCode === 'STG') {
                return new AuditBatchResult(
                    verdicts: [],
                    omissions: [$this->malformedId => AuditOutcomeReason::VerdictMissing],
                    inputTokens: 10, outputTokens: 5, judgeModel: 'stub', latencyMs: 1,
                );
            }

            return new AuditBatchResult(
                verdicts: [$this->judgedId => new AuditVerdict($this->judgedId, 0.85, ['relevance' => 0.85, 'calibration' => 0.9, 'grounding' => 0.85])],
                omissions: [],
                inputTokens: 10, outputTokens: 5, judgeModel: 'stub', latencyMs: 1,
            );
        }
    };
    app()->instance(AuditJudge::class, $judge);

    AuditEvaluationJob::dispatch($evaluation->id, null, null);

    $run = IndicatorScoreAuditRun::withoutGlobalScopes()->where('evaluation_id', $evaluation->id)->firstOrFail();

    expect($run->indicators_judged)->toBe(1)
        ->and($run->indicators_skipped)->toBe(1)
        ->and($run->indicators_unavailable)->toBe(1)
        ->and($run->indicators_malformed)->toBe(1)
        ->and($run->indicators_total)->toBe(4)
        ->and($run->indicators_total)->toBe(
            $run->indicators_judged + $run->indicators_skipped + $run->indicators_unavailable + $run->indicators_malformed
        );
});

test('a raw insert whose four counters do not sum to indicators_total is rejected by the database CHECK', function (): void {
    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $fv = FrameworkVersion::factory()->create(['organization_id' => $org->id]);
    $project = Project::factory()->create(['framework_version_id' => $fv->id]);
    $participant = Participant::factory()->forProject($project)->withStatus('completato')->create();
    $evaluation = Evaluation::factory()->completed()->create(['participant_id' => $participant->id]);

    expect(function () use ($org, $evaluation): void {
        DB::table('indicator_score_audit_runs')->insert([
            'organization_id' => $org->id,
            'evaluation_id' => $evaluation->id,
            'status' => 'completed',
            'failure_reason' => null,
            'indicators_total' => 10,
            'indicators_judged' => 1,
            'indicators_skipped' => 1,
            'indicators_unavailable' => 1,
            'indicators_malformed' => 1,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'estimated_cost_usd' => null,
            'latency_ms' => 0,
            'judge_model_version' => 'jev-1',
            'audit_prompt_version' => '1.0.0',
            'created_at' => now(),
        ]);
    })->toThrow(QueryException::class);
});
