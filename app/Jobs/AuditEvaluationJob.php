<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\AuditJudge;
use App\DTOs\Audit\AuditRequest;
use App\DTOs\Audit\AuditSubject;
use App\Enums\Audit\AuditOutcomeReason;
use App\Enums\Audit\AuditRunStatus;
use App\Enums\Audit\AuditVerdictStatus;
use App\Exceptions\Audit\AuditJudgeException;
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
 * controller.
 *
 * Per-competency `Throwable` isolation (P3b, design D6/AD-3): a failure
 * judging one competency never aborts the run — every other competency is
 * still judged, and the failed competency's judgeable indicators receive
 * `unavailable` rows. A verdict absent from an otherwise-successful call is
 * the distinct `malformed` case (spec: "A Per-Subject Malformed Verdict Is
 * Distinct From A Competency-Level Unavailable Outcome").
 *
 * Run `status` derivation (P3b) is read directly off design D6's own
 * pseudocode structure: `runFailureReason` is set ONLY inside the per-
 * competency `catch` block, never elsewhere — so by construction, a run
 * with zero throws stays `completed` even when it carries `malformed`
 * verdicts (those are visible via `indicators_malformed`, not via `status`).
 * `partial` when at least one but not every judgeable competency threw;
 * `failed` when every judgeable competency threw (0 judged).
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

        // Run-level failure tracking (D6's pseudocode: `runFailureReason ??=
        // 'judge_unavailable'` — set ONLY inside the catch branch, first
        // throw wins). `$attemptedAnyJudgeCall`/`$everyAttemptedCallThrew`
        // together derive `failed` vs `partial` vs `completed` below.
        $runFailureReason = null;
        $attemptedAnyJudgeCall = false;
        $everyAttemptedCallThrew = true;

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

            $attemptedAnyJudgeCall = true;

            try {
                $result = $judge->judge(new AuditRequest($competencyResult->competency_code, $subjects));
            } catch (Throwable $e) {
                // Per-competency isolation (AD-3, design D6): this
                // competency's vendor call could not be completed at all —
                // every one of its judgeable subjects is `unavailable`,
                // never `malformed` (that status is reserved for a call
                // that DID succeed but a specific subject's verdict was
                // unusable).
                $reason = $this->unavailableReasonFor($e);

                foreach ($judgeable as $indicator) {
                    $bufferedAudits[] = [
                        'indicator_score_id' => $indicator->id,
                        'status' => AuditVerdictStatus::Unavailable->value,
                        'support_probability' => null,
                        'question_probabilities' => null,
                        'outcome_reason' => $reason->value,
                    ];
                }

                $runFailureReason ??= 'judge_unavailable';

                continue;
            }

            $everyAttemptedCallThrew = false;

            $inputTokens += $result->inputTokens;
            $outputTokens += $result->outputTokens;
            $latencyMs += $result->latencyMs;
            $judgeModelVersion = $result->judgeModel;

            foreach ($judgeable as $indicator) {
                $verdict = $result->verdicts[$indicator->id] ?? null;

                if ($verdict === null) {
                    // A verdict absent from a call that itself succeeded is
                    // the distinct `malformed` case (spec: "A Per-Subject
                    // Malformed Verdict Is Distinct From A Competency-Level
                    // Unavailable Outcome"). `JevResponseMapper` always
                    // records a reason for every subject it omits
                    // (`AuditBatchResult::$omissions`); the fallback below is
                    // defensive only — reachable if a future `AuditJudge`
                    // implementation violates that invariant.
                    $reason = $result->omissions[$indicator->id] ?? AuditOutcomeReason::VerdictMissing;

                    $bufferedAudits[] = [
                        'indicator_score_id' => $indicator->id,
                        'status' => AuditVerdictStatus::Malformed->value,
                        'support_probability' => null,
                        'question_probabilities' => null,
                        'outcome_reason' => $reason->value,
                    ];

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

        [$runStatus, $runFailureReason] = match (true) {
            $attemptedAnyJudgeCall && $everyAttemptedCallThrew => [AuditRunStatus::Failed, $runFailureReason ?? 'judge_unavailable'],
            $runFailureReason !== null => [AuditRunStatus::Partial, $runFailureReason],
            default => [AuditRunStatus::Completed, null],
        };

        DB::transaction(function () use ($evaluation, $counters, $inputTokens, $outputTokens, $estimatedCostUsd, $latencyMs, $judgeModelVersion, $bufferedAudits, $runStatus, $runFailureReason): void {
            $run = IndicatorScoreAuditRun::create([
                'evaluation_id' => $evaluation->id,
                'requested_by_user_id' => $this->requestedByUserId,
                'status' => $runStatus->value,
                'failure_reason' => $runFailureReason,
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

    /**
     * Classifies a per-competency judge failure into one of the three
     * `unavailable` reasons (design C-E). `AuditJudgeException` carries no
     * richer machine-readable classification than `isRetryable()` and a
     * `previous` throwable — deliberately NOT `$e->getMessage()` (design
     * D3's own rule: "the reason is a machine key, never $e->getMessage()").
     *
     * `getPrevious() !== null` is exactly `TypesafeJevJudge`'s transport
     * branch (connection refused, DNS failure, or a timeout collapsed
     * together — see below) — the vendor could not be reached at all.
     * A present `AuditJudgeException` with no previous is a non-2xx response
     * or an unparseable/malformed envelope — the vendor WAS reached.
     *
     * KNOWN LIMITATION, documented rather than silently guessed:
     * `AuditOutcomeReason::JudgeTimeout` is not reachable from this method.
     * `TypesafeJevJudge` wraps every `Http::withHeaders()->timeout()->post()`
     * throwable (including a genuine timeout) into the same transport
     * branch as a connection failure, and distinguishing them would mean
     * inspecting the vendor SDK's own exception hierarchy for a wire that
     * design.md's own C-C already flags UNVERIFIED. `JudgeTimeout` stays a
     * legal, reachable-in-principle `AuditOutcomeReason` case for when that
     * verification lands; forcing a guess now would be the same mistake
     * C-C already warns against.
     */
    private function unavailableReasonFor(Throwable $e): AuditOutcomeReason
    {
        if ($e instanceof AuditJudgeException && $e->getPrevious() !== null) {
            return AuditOutcomeReason::JudgeUnreachable;
        }

        if ($e instanceof AuditJudgeException) {
            return AuditOutcomeReason::JudgeHttpError;
        }

        // Any other Throwable — e.g. a defensive type guard elsewhere in the
        // judge chain raising something other than AuditJudgeException.
        // The safest generic classification: the vendor could not be
        // usefully reached for this competency.
        return AuditOutcomeReason::JudgeUnreachable;
    }

    private function releaseLock(): void
    {
        if ($this->lockOwner === null) {
            return;
        }

        Cache::restoreLock("audit:evaluation:{$this->evaluationId}", $this->lockOwner)->release();
    }

    /**
     * Best-effort degraded-row write for design D6's KNOWN CEILING: a worker
     * kill mid-run ($timeout exceeded, OOM, container restart during
     * deploy) loses the cost record of calls already made — the *attempt*
     * is recorded even though the *spend* is not (tasks.md P3b.13's own
     * framing). Mirrors `ScoreEvaluationJob::failed()`'s "org not derivable
     * → log and skip the tenant-scoped write" branch. Writes NO indicator
     * rows — only the run row, with all four counters at 0 (satisfying the
     * coverage CHECK: `0 = 0+0+0+0`) and `failure_reason = 'job_killed'`.
     *
     * The lock is released here unconditionally, in every branch — this is
     * a SEPARATE invocation from handle() and does not share its `finally`.
     */
    public function failed(Throwable $e): void
    {
        Log::error('AuditEvaluationJob: job exhausted retries — writing best-effort failed run row (design D6 known ceiling)', [
            'evaluation_id' => $this->evaluationId,
            'error' => $e->getMessage(),
        ]);

        $evaluation = Evaluation::withoutGlobalScopes()->find($this->evaluationId);
        $orgId = $evaluation?->organization_id;

        if ($evaluation === null || $orgId === null || $orgId < 1) {
            Log::error('AuditEvaluationJob: cannot derive organization context in failed() — skipping the tenant-scoped write', [
                'evaluation_id' => $this->evaluationId,
            ]);

            $this->releaseLock();

            return;
        }

        TenantContextScope::runFor($orgId, function () use ($evaluation): void {
            IndicatorScoreAuditRun::create([
                'evaluation_id' => $evaluation->id,
                'requested_by_user_id' => $this->requestedByUserId,
                'status' => AuditRunStatus::Failed->value,
                'failure_reason' => 'job_killed',
                'indicators_total' => 0,
                'indicators_judged' => 0,
                'indicators_skipped' => 0,
                'indicators_unavailable' => 0,
                'indicators_malformed' => 0,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'estimated_cost_usd' => null,
                'latency_ms' => 0,
                'judge_model_version' => (string) config('scoring.audit.judge_model'),
                'audit_prompt_version' => (string) config('scoring.audit.prompt_version'),
            ]);
        });

        $this->releaseLock();
    }
}
