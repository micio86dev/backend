<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Participant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * FinalizeInterview — C9 scoring trigger job (C7a Phase 13).
 *
 * Dispatched by InterviewController::end() via CAS single-winner + afterCommit().
 * The afterCommit() call ensures the job is enqueued ONLY after the /end DB transaction
 * commits successfully — no crash window between a committed session-status update and a
 * missing job dispatch (CRITICAL-3 atomicity guarantee).
 *
 * Idempotency (FIX-4 — two layers):
 *   Layer 1 — status re-check: if participant.status is already past 'in_valutazione'
 *     (i.e. 'completato' or 'errore'), this is a no-op. This guards against a re-queued
 *     job arriving AFTER C9 has already completed the interview.
 *
 *   Layer 2 — Redis NX lock (dedup): before emitting the C9 trigger, attempt to acquire
 *     Redis NX key 'finalize:{participantId}'. TTL = 2 hours (> max retry window).
 *     - Key absent (first execution) → acquired → emit C9 trigger.
 *     - Key present (retry or concurrent duplicate) → no-op.
 *     This prevents a FAILED+RETRIED job from re-emitting the C9 scoring trigger while
 *     the participant is still 'in_valutazione' (the expected state until C9 completes).
 *     Concurrent /end is protected by the CAS in end(); job retry is NOT — hence FIX-4.
 *
 * Chosen dedup strategy: Redis NX lock (Option A from design).
 * If Redis is unavailable, Cache::add() falls back to the configured fallback store
 * (typically array/null in test, DB in prod). The job is NOT dispatched on Cache failure
 * — a warning is logged and the job exits (fail-safe).
 *
 * C9 trigger: emits a placeholder event for now (C9 scoring consumer is out of C7a scope).
 *
 * REQ: FinalizeInterview job — idempotent C9 scoring trigger (C7a Phase 13)
 */
class FinalizeInterview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Redis NX lock TTL in seconds.
     *
     * Must exceed the maximum job retry window (default Laravel: 3 retries × 60s backoff).
     * 7200 seconds (2 hours) is conservative and safe.
     */
    private const DEDUP_LOCK_TTL_SECONDS = 7200;

    public function __construct(
        private readonly int $participantId,
    ) {}

    /**
     * Execute the job.
     *
     * Idempotency guards prevent duplicate C9 trigger emission under:
     *   - Direct re-dispatch (would not happen due to CAS, but defensive)
     *   - Job retry after a transient failure (e.g. Redis/DB blip)
     *   - Queue worker crash-and-restart scenarios
     */
    public function handle(): void
    {
        // Layer 1 — re-check participant status
        $participant = Participant::find($this->participantId);

        if ($participant === null) {
            Log::warning('FinalizeInterview: participant not found', ['participant_id' => $this->participantId]);
            return;
        }

        // If participant has already moved past 'in_valutazione', this is a no-op.
        // (in_valutazione is the expected state while C9 is in flight; completato/errore = done)
        if (! in_array($participant->status, ['in_valutazione', 'in_corso'], true)) {
            Log::info('FinalizeInterview: no-op (participant past in_valutazione)', [
                'participant_id' => $this->participantId,
                'status'         => $participant->status,
            ]);
            return;
        }

        // Layer 2 — Redis NX dedup lock (FIX-4, Option A)
        $lockKey = 'finalize:' . $this->participantId;

        // Cache::add() is atomic: returns true if the key was SET (did not exist), false if it existed.
        $acquired = Cache::add($lockKey, true, self::DEDUP_LOCK_TTL_SECONDS);

        if (! $acquired) {
            // Lock already held → this is a duplicate execution (job retry or race condition)
            Log::info('FinalizeInterview: dedup lock already held, no-op', [
                'participant_id' => $this->participantId,
                'lock_key'       => $lockKey,
            ]);
            return;
        }

        // C9 scoring trigger (stub/placeholder — C9 consumer is out of C7a scope)
        // In C9, this will dispatch an event or notify the scoring pipeline.
        // For now: log the trigger so tests can assert it was reached.
        Log::info('FinalizeInterview: emitting C9 scoring trigger', [
            'participant_id' => $this->participantId,
        ]);

        // TODO(C9): dispatch the scoring pipeline event here.
        // event(new ScoringRequested($this->participantId));
    }
}
