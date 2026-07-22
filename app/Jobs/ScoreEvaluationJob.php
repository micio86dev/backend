<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\EvaluationStatus;
use App\Events\EvaluationFailed;
use App\Models\Evaluation;
use App\Models\Participant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ScoreEvaluationJob — async BARS scoring orchestrator (C9 — Scoring Engine).
 *
 * Dispatched from FinalizeInterview (via ScoringRequested event + DispatchScoringJob
 * listener — wired in PR3). Runs on Horizon; processes all competencies sequentially.
 *
 * PR1 SCOPE: Start-of-job guard + Evaluation row creation + failed() skeleton.
 * The scoring pipeline body (PR2) is represented by a placeholder comment.
 *
 * Start-of-job guard (D2 CC4 — idempotency):
 *   Step 1: participant.status == 'errore' → no-op (log + return).
 *   Step 2: load Evaluation row → branch:
 *     - No row: create Evaluation(status=processing, versions) → score normally.
 *     - 23505 concurrent INSERT: catch + reload + re-enter guard.
 *     - {completed|pending} + retry_attempt=false → no-op.
 *     - processing → resume-skip path (PR2 skips scored competencies).
 *
 * failed() (D9 CC5):
 *   (a) Guard: transition participant in_valutazione → errore ONLY if currently in_valutazione.
 *   (b) ALWAYS emit EvaluationFailed($participantId) regardless of transition outcome.
 *
 * REQ: ScoreEvaluationJob skeleton + failed() (C9 D2/D9)
 */
class ScoreEvaluationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum queue-level retry attempts.
     *
     * Distinct from domain retry RT-B (D10). Queue retries handle transient
     * infrastructure failures (DB blip, network timeout). RT-B handles domain
     * re-scoring after a 'pending' evaluation.
     *
     * Value mirrors the Horizon configuration for the scoring queue.
     */
    public int $tries = 3;

    /**
     * Backoff in seconds between queue-level retry attempts.
     */
    public int $backoff = 60;

    public function __construct(
        private readonly int $participantId,
        private readonly bool $retryAttempt = false,
    ) {}

    /**
     * Execute the scoring job.
     *
     * PR1: Implements the start-of-job guard and Evaluation row creation.
     * The scoring pipeline body (PR2) is stubbed with a placeholder.
     */
    public function handle(): void
    {
        // ── Step 1: Participant errore guard ──────────────────────────────────
        // Runs BEFORE loading any Evaluation row. errore is the terminal guard:
        // once a participant is errore, no further scoring is ever performed.
        $participant = Participant::withoutGlobalScopes()->find($this->participantId);

        if ($participant === null) {
            Log::warning('ScoreEvaluationJob: participant not found', [
                'participant_id' => $this->participantId,
            ]);

            return;
        }

        if ($participant->status === 'errore') {
            Log::info('ScoreEvaluationJob: participant already errore — no-op', [
                'participant_id' => $this->participantId,
            ]);

            return;
        }

        // ── Step 2: Evaluation row guard ─────────────────────────────────────
        $this->enterEvaluationGuard($participant);
    }

    /**
     * Start-of-job guard step 2: load or create the Evaluation row.
     *
     * Handles the 4 branches from D2:
     *   - No row → create in processing + proceed.
     *   - 23505 concurrent INSERT → reload + re-enter (max 1 re-entry).
     *   - {completed|pending} + retry_attempt=false → no-op.
     *   - processing → resume-skip path.
     *
     * @param  bool  $reentrant  True when called after a 23505 catch (prevents infinite loop).
     */
    private function enterEvaluationGuard(Participant $participant, bool $reentrant = false): void
    {
        // Use withoutGlobalScopes to load across tenant boundary inside the job.
        // The job is dispatched for a specific participant; cross-tenant isolation
        // is enforced by the participantId (minted per org by C6).
        $evaluation = Evaluation::withoutGlobalScopes()
            ->where('participant_id', $this->participantId)
            ->first();

        if ($evaluation === null) {
            // No row exists → create in processing and proceed.
            try {
                $evaluation = Evaluation::withoutGlobalScopes()->create([
                    'participant_id' => $this->participantId,
                    'status' => EvaluationStatus::Processing->value,
                    'framework_version_id' => $this->resolveFrameworkVersionId($participant),
                    'model_version' => config('scoring.model_version'),
                    'prompt_version' => config('scoring.prompt_version'),
                    'evaluated_at' => null,
                    'retry_attempt' => false,
                ]);
            } catch (UniqueConstraintViolationException) {
                // 23505: concurrent job won the INSERT race. Reload and re-enter guard.
                if ($reentrant) {
                    // Guard against infinite loop — should not happen in practice.
                    Log::error('ScoreEvaluationJob: 23505 re-entry loop detected', [
                        'participant_id' => $this->participantId,
                    ]);

                    return;
                }

                Log::info('ScoreEvaluationJob: 23505 concurrent INSERT — reloading and re-entering guard', [
                    'participant_id' => $this->participantId,
                ]);

                $this->enterEvaluationGuard($participant, reentrant: true);

                return;
            }

            Log::info('ScoreEvaluationJob: Evaluation created, starting scoring', [
                'participant_id' => $this->participantId,
                'evaluation_id' => $evaluation->id,
            ]);

            // TODO(PR2): $this->runScoringPipeline($evaluation);
            return;
        }

        // Existing Evaluation row found — branch on status (cast to EvaluationStatus by the model).
        $status = $evaluation->status;

        if ($status === EvaluationStatus::Processing) {
            // Resume-skip path: a previous job started but did not complete.
            // PR2 will skip already-scored competencies (CompetencyResult rows).
            Log::info('ScoreEvaluationJob: Evaluation in processing — resuming', [
                'participant_id' => $this->participantId,
                'evaluation_id' => $evaluation->id,
            ]);

            // TODO(PR2): $this->runScoringPipeline($evaluation);
            return;
        }

        // Terminal status ({completed|pending}) with retryAttempt=false → no-op.
        if (! $this->retryAttempt) {
            Log::info('ScoreEvaluationJob: Evaluation already terminal — no-op', [
                'participant_id' => $this->participantId,
                'evaluation_id' => $evaluation->id,
                'status' => $status->value,
            ]);

            return;
        }

        // retryAttempt=true + pending → domain retry RT-B path (PR4).
        // Not implemented in PR1.
        Log::info('ScoreEvaluationJob: domain retry path — deferred to PR4', [
            'participant_id' => $this->participantId,
        ]);
    }

    /**
     * Resolve the framework_version_id for this participant.
     *
     * In the full implementation (PR2), this reads from the participant's project.
     * For PR1, we read from the participant's project directly.
     */
    private function resolveFrameworkVersionId(Participant $participant): int
    {
        // Load participant's project to get the pinned framework_version_id.
        $project = $participant->project()->withoutGlobalScopes()->first();

        if ($project === null) {
            // Fallback: should not happen in production; guard for test/edge cases.
            throw new \RuntimeException(
                "ScoreEvaluationJob: cannot resolve framework_version_id for participant {$this->participantId} — project not found."
            );
        }

        return (int) $project->framework_version_id;
    }

    /**
     * Handle job failure after all queue retries are exhausted (D9 CC5).
     *
     * (a) Guard: transition participant in_valutazione → errore ONLY if currently in_valutazione.
     *     If participant is already errore (e.g. race with concurrent failed()), skip transition.
     * (b) ALWAYS emit EvaluationFailed($participantId) regardless of transition outcome.
     *
     * This ensures PRs 1–2 cannot leave participants orphaned in in_valutazione on failure.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('ScoreEvaluationJob: job exhausted retries', [
            'participant_id' => $this->participantId,
            'error' => $e->getMessage(),
        ]);

        // (a) Guard transition: only if currently in_valutazione.
        $participant = Participant::withoutGlobalScopes()->find($this->participantId);

        if ($participant !== null && $participant->status === 'in_valutazione') {
            try {
                $participant->status = 'errore';
                $participant->save();

                Log::info('ScoreEvaluationJob: participant transitioned to errore', [
                    'participant_id' => $this->participantId,
                ]);
            } catch (\Throwable $transitionException) {
                Log::error('ScoreEvaluationJob: failed to transition participant to errore', [
                    'participant_id' => $this->participantId,
                    'error' => $transitionException->getMessage(),
                ]);
            }
        } elseif ($participant !== null && $participant->status === 'errore') {
            Log::info('ScoreEvaluationJob: participant already errore — skipping transition', [
                'participant_id' => $this->participantId,
            ]);
        }

        // (b) ALWAYS emit EvaluationFailed, regardless of transition outcome.
        event(new EvaluationFailed($this->participantId));
    }
}
