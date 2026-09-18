<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AuditJudge;
use App\DTOs\Audit\AuditRequest;
use App\DTOs\Audit\AuditSubject;
use App\Enums\Audit\AuditOutcomeReason;
use App\Enums\Audit\AuditRunStatus;
use App\Enums\Audit\AuditVerdictStatus;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\IndicatorScore;
use App\Models\IndicatorScoreAudit;
use App\Models\IndicatorScoreAuditRun;
use App\Support\Observability\AuditRunCostEstimator;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Post-hoc audit of persisted indicator scores (scoring-audit-jev, proposal
 * AD-5, design D6/D7). Trigger-agnostic (spec: "Audit Runs Are Operator-
 * Triggered Only") — this job dispatches from nothing in v1 except the P4
 * controller (not yet built as of P3a).
 *
 * HAPPY PATH ONLY in this slice (P3a) — every judge() call is assumed to
 * succeed. Per-competency `Throwable` isolation, degraded rows, and
 * `failed()`'s best-effort write are P3b's scope (design D6's "KNOWN
 * CEILING" section, tasks.md P3b.1-P3b.19).
 *
 * The run row is written ONCE, at the end, in one transaction (D6) — verdicts
 * are buffered in memory while every competency is judged, then the run row
 * and every indicator_score_audits row are inserted together, run first
 * (the audit rows need its id).
 */
final class AuditEvaluationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * A queue retry of this job re-pays TypeSafe for the WHOLE evaluation.
     * Every failure mode this slice's successor (P3b) converts into a
     * recorded degraded row rather than a thrown exception, so the only
     * failures that reach the queue's retry machinery are ones a retry
     * cannot fix — design D7.
     */
    public int $tries = 1;

    /**
     * Derived, not chosen (C-F/D7): MAX_ROLE_COMPETENCIES (18) x
     * scoring.audit.timeout_seconds (30) x 1.1 = 594 -> ceil to 600. MUST
     * stay strictly below queue.runtime.worker_timeout (1260,
     * config/queue.php) or the worker refuses to boot
     * (QueueRuntimeInvariant).
     */
    public int $timeout = 600;

    public function __construct(
        private readonly int $evaluationId,
        private readonly ?int $requestedByUserId = null,
        private readonly ?string $lockOwner = null,
    ) {}

    public function handle(AuditJudge $judge, AuditRunCostEstimator $costEstimator): void
    {
        try {
            // Re-checked HERE, not only at the P4 controller (spec: "Setting
            // enabled to false MUST make the job no-op if somehow
            // dispatched, with no partial rows written"). First line of the
            // job, before any read.
            if (! (bool) config('scoring.audit.enabled')) {
                Log::info('AuditEvaluationJob: audit disabled at execution time — no-op', [
                    'evaluation_id' => $this->evaluationId,
                ]);

                return;
            }

            // Org is derived from the aggregate root, NEVER from ambient
            // TenantResolver state (Queue::before already reset it to null)
            // and NEVER from the job's serialized payload — mirrors
            // ScoreEvaluationJob's own step 2 (design.md Multi-Tenancy
            // table).
            $evaluation = Evaluation::withoutGlobalScopes()->find($this->evaluationId);

            if ($evaluation === null) {
                Log::warning('AuditEvaluationJob: evaluation not found', [
                    'evaluation_id' => $this->evaluationId,
                ]);

                return;
            }

            $orgId = $evaluation->organization_id;

            if ($orgId < 1) {
                Log::error('AuditEvaluationJob: evaluation has no resolvable organization_id — aborting before any write', [
                    'evaluation_id' => $this->evaluationId,
                    'organization_id' => $orgId,
                ]);

                return;
            }

            TenantContextScope::runFor($orgId, function () use ($judge, $costEstimator, $evaluation): void {
                $this->runAudit($judge, $costEstimator, $evaluation);
            });
        } finally {
            // Regardless of outcome — success, early return, or an
            // unexpected throw — the lock the P4 controller handed this job
            // must never outlive it (design D7).
            $this->releaseLock();
        }
    }

    private function runAudit(AuditJudge $judge, AuditRunCostEstimator $costEstimator, Evaluation $evaluation): void
    {
        $competencyResults = CompetencyResult::where('evaluation_id', $evaluation->id)
            ->with('indicatorScores')
            ->get();

        /** @var list<array{indicator_score_id:int, status:string, support_probability:float|null, question_probabilities:array<string,float>|null, outcome_reason:string|null}> $bufferedAudits */
        $bufferedAudits = [];
        $inputTokens = 0;
        $outputTokens = 0;
        $latencyMs = 0;
        $judgeModelVersion = (string) config('scoring.audit.judge_model');

        foreach ($competencyResults as $competencyResult) {
            [$skipped, $judgeable] = $this->partition($competencyResult->indicatorScores);

            foreach ($skipped as $skippedPair) {
                $bufferedAudits[] = $this->skippedRow($skippedPair[0], $skippedPair[1]);
            }

            if ($judgeable === []) {
                continue;
            }

            $subjects = array_map(
                static fn (IndicatorScore $indicator): AuditSubject => new AuditSubject(
                    indicatorScoreId: $indicator->id,
                    position: $indicator->position,
                    indicatorText: $indicator->indicator_text,
                    score: $indicator->score,
                    explanation: $indicator->explanation,
                    excerpts: array_values($indicator->excerpts),
                ),
                $judgeable,
            );

            $result = $judge->judge(new AuditRequest($competencyResult->competency_code, $subjects));

            $inputTokens += $result->inputTokens;
            $outputTokens += $result->outputTokens;
            $latencyMs += $result->latencyMs;
            $judgeModelVersion = $result->judgeModel;

            foreach ($judgeable as $indicator) {
                $verdict = $result->verdicts[$indicator->id] ?? null;

                if ($verdict === null) {
                    // A verdict absent from a call that itself succeeded is
                    // the `malformed` case — P3b wires the reason channel
                    // and the row write for it (design C-C known gap,
                    // tasks.md P3b.5-P3b.8). Not reachable in this slice:
                    // FakeAuditJudge's default behaviour always returns a
                    // verdict for every subject sent.
                    continue;
                }

                $bufferedAudits[] = [
                    'indicator_score_id' => $indicator->id,
                    'status' => AuditVerdictStatus::Judged->value,
                    'support_probability' => $verdict->supportProbability,
                    'question_probabilities' => $verdict->questionProbabilities,
                    'outcome_reason' => null,
                ];
            }
        }

        $counters = $this->reconcileCounters($bufferedAudits);
        $estimatedCostUsd = $costEstimator->estimate($judgeModelVersion, $inputTokens, $outputTokens);

        DB::transaction(function () use ($evaluation, $counters, $inputTokens, $outputTokens, $estimatedCostUsd, $latencyMs, $judgeModelVersion, $bufferedAudits): void {
            $run = IndicatorScoreAuditRun::create([
                'evaluation_id' => $evaluation->id,
                'requested_by_user_id' => $this->requestedByUserId,
                'status' => AuditRunStatus::Completed->value,
                'failure_reason' => null,
                'indicators_total' => $counters['total'],
                'indicators_judged' => $counters['judged'],
                'indicators_skipped' => $counters['skipped'],
                'indicators_unavailable' => $counters['unavailable'],
                'indicators_malformed' => $counters['malformed'],
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'estimated_cost_usd' => $estimatedCostUsd,
                'latency_ms' => $latencyMs,
                'judge_model_version' => $judgeModelVersion,
                'audit_prompt_version' => (string) config('scoring.audit.prompt_version'),
            ]);

            foreach ($bufferedAudits as $audit) {
                IndicatorScoreAudit::create([
                    'audit_run_id' => $run->id,
                    ...$audit,
                ]);
            }
        });
    }

    /**
     * Partitions one competency's indicators into skipped/judgeable
     * (design D5). Order is LOAD-BEARING: `score === -1` is tested FIRST,
     * `excerpts === []` second — see D5's own reasoning for the anomalous
     * case this ordering closes (a -1 row that somehow carries excerpts
     * must never be judged, since a -1's only legitimate excerpt state is
     * empty).
     *
     * @param  Collection<int, IndicatorScore>  $indicators
     * @return array{0: list<array{0: IndicatorScore, 1: AuditOutcomeReason}>, 1: list<IndicatorScore>}
     */
    private function partition(Collection $indicators): array
    {
        $skipped = [];
        $judgeable = [];

        foreach ($indicators as $indicator) {
            if ($indicator->score === -1) {
                $skipped[] = [$indicator, AuditOutcomeReason::UnassessableByConstruction];

                continue;
            }

            if ($indicator->excerpts === []) {
                $skipped[] = [$indicator, AuditOutcomeReason::AssessedWithoutExcerpts];

                continue;
            }

            $judgeable[] = $indicator;
        }

        return [$skipped, $judgeable];
    }

    /**
     * @return array{indicator_score_id:int, status:string, support_probability:null, question_probabilities:null, outcome_reason:string}
     */
    private function skippedRow(IndicatorScore $indicator, AuditOutcomeReason $reason): array
    {
        return [
            'indicator_score_id' => $indicator->id,
            'status' => AuditVerdictStatus::Skipped->value,
            'support_probability' => null,
            'question_probabilities' => null,
            'outcome_reason' => $reason->value,
        ];
    }

    /**
     * @param  list<array{indicator_score_id:int, status:string, support_probability:float|null, question_probabilities:array<string,float>|null, outcome_reason:string|null}>  $bufferedAudits
     * @return array{total:int, judged:int, skipped:int, unavailable:int, malformed:int}
     */
    private function reconcileCounters(array $bufferedAudits): array
    {
        $judged = 0;
        $skipped = 0;
        $unavailable = 0;
        $malformed = 0;

        foreach ($bufferedAudits as $audit) {
            match ($audit['status']) {
                AuditVerdictStatus::Judged->value => $judged++,
                AuditVerdictStatus::Skipped->value => $skipped++,
                AuditVerdictStatus::Unavailable->value => $unavailable++,
                AuditVerdictStatus::Malformed->value => $malformed++,
                default => throw new \LogicException("Unrecognised AuditVerdictStatus value buffered: {$audit['status']}"),
            };
        }

        return [
            'total' => $judged + $skipped + $unavailable + $malformed,
            'judged' => $judged,
            'skipped' => $skipped,
            'unavailable' => $unavailable,
            'malformed' => $malformed,
        ];
    }

    private function releaseLock(): void
    {
        if ($this->lockOwner === null) {
            return;
        }

        Cache::restoreLock("audit:evaluation:{$this->evaluationId}", $this->lockOwner)->release();
    }

    /**
     * P3b implements the full best-effort degraded-row write for the D6
     * "KNOWN CEILING" case (a worker kill mid-run). Not wired here — out of
     * this slice's happy-path scope. The lock is still released via
     * handle()'s own `finally` for every failure that reaches THIS method
     * (queue exhaustion after $tries), because `failed()` is a SEPARATE
     * invocation from handle() and does not share its finally block.
     */
    public function failed(Throwable $e): void
    {
        Log::error('AuditEvaluationJob: job exhausted retries (P3b implements the degraded-row write)', [
            'evaluation_id' => $this->evaluationId,
            'error' => $e->getMessage(),
        ]);

        $this->releaseLock();
    }
}
