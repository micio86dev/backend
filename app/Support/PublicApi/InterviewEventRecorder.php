<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

use App\Models\InterviewEvent;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records `InterviewEvent` rows at the existing candidate/scoring seams
 * (public-api step 6, G-38) — `session_started`, `question_asked`,
 * `answer_recorded`, `session_ended`, `under_evaluation`, `transcript_ready`,
 * `completed`, `error`, `scoring_ready`. One shared writer, called from
 * `App\Http\Controllers\Candidate\InterviewController::start()`/`end()`,
 * `App\Http\Controllers\Candidate\UtteranceController::store()`,
 * `App\Actions\Interview\SettleParticipantCompletion` and
 * `App\Jobs\ScoreEvaluationJob` — never a second, independent writer, so
 * "what counts as an interview event" stays defined in exactly one place.
 *
 * NEVER THROWS INTO THE CALLER (G-38's own "without changing any existing
 * behaviour"): every method here is best-effort observability bolted onto a
 * seam whose OWN job — starting a session, recording a candidate's answer,
 * settling a completion, scoring an evaluation — must never fail, retry, or
 * roll back because the EVENT LOG could not be written. A failure here is
 * logged at ERROR and swallowed.
 *
 * WRITES INSIDE THE CALLER'S TRANSACTION WHEN ONE IS OPEN — `InterviewEvent::
 * create()` runs on the same database connection as any ambient
 * `DB::transaction()` the caller is already inside, so it participates in
 * (and rolls back with) that transaction automatically; nothing here opens
 * a nested transaction of its own. The one documented exception is the
 * live-utterance seam (`UtteranceController::store()`), which records its
 * event AFTER that method's own short, lock-held transaction commits — see
 * that call site's own comment for why (Z16: the locked critical section
 * must stay exactly the three statements it already needs).
 *
 * `TenantContextScope::runFor()` wraps every write unconditionally, whether
 * or not ambient tenant context is already established — it is fully
 * reentrant (restores the PREVIOUS context, not a hard reset — see that
 * class's own docblock), so this is correct both from an authenticated HTTP
 * request (candidate controllers, already tenant-scoped) and from a queued
 * job or a raw `Participant::where(...)->update(...)` call site that never
 * loaded a tenant-scoped model at all (`SettleParticipantCompletion`,
 * `ScoreEvaluationJob`).
 *
 * Only a participant with a `public_id` produces a readable `evt_` id on
 * `GET /v1/interviews/{id}/events` — every participant has one since the
 * public-api step 5 migration backfilled and then required it (`NOT NULL`),
 * so this class does not special-case its absence.
 */
final class InterviewEventRecorder
{
    public static function sessionStarted(int $organizationId, int $participantId): void
    {
        self::record($organizationId, $participantId, 'session_started');
    }

    /**
     * `$questionIndex` is the G-37 ordinal — the 0-based position of the
     * primary question within its competency, derived by the caller from
     * `App\Support\Interview\TurnClassifier` against the session's
     * `primary_questions` snapshot. A follow-up avatar turn carries the
     * SAME ordinal as the primary question it follows (G-38).
     */
    public static function questionAsked(int $organizationId, int $participantId, string $competencyCode, int $questionIndex): void
    {
        self::record($organizationId, $participantId, 'question_asked', [
            'competency_code' => $competencyCode,
            'question_index' => $questionIndex,
        ]);
    }

    public static function answerRecorded(int $organizationId, int $participantId, string $competencyCode, int $questionIndex): void
    {
        self::record($organizationId, $participantId, 'answer_recorded', [
            'competency_code' => $competencyCode,
            'question_index' => $questionIndex,
        ]);
    }

    public static function sessionEnded(int $organizationId, int $participantId): void
    {
        self::record($organizationId, $participantId, 'session_ended');
    }

    public static function underEvaluation(int $organizationId, int $participantId): void
    {
        self::record($organizationId, $participantId, 'under_evaluation');
    }

    public static function transcriptReady(int $organizationId, int $participantId): void
    {
        self::record($organizationId, $participantId, 'transcript_ready');
    }

    public static function completed(int $organizationId, int $participantId): void
    {
        self::record($organizationId, $participantId, 'completed');
    }

    public static function error(int $organizationId, int $participantId): void
    {
        self::record($organizationId, $participantId, 'error');
    }

    public static function scoringReady(int $organizationId, int $participantId): void
    {
        self::record($organizationId, $participantId, 'scoring_ready');
    }

    /**
     * `DB::transaction()` wraps the write itself (gga finding 1, HIGH) —
     * not merely `try`/`catch`. A caller here (`/end`, `handleIssuePending()`
     * `start()`, `SettleParticipantCompletion`, `ScoreEvaluationJob`) is
     * almost always ALREADY inside its own `DB::transaction()`; a failed
     * `InterviewEvent::create()` inside that OUTER transaction, caught only
     * in PHP, leaves the Postgres CONNECTION itself in an ABORTED state —
     * `try`/`catch` tells PHP the write failed, but tells Postgres nothing,
     * and every later statement on that connection then fails with
     * `25P02 current transaction is aborted`. Concretely: `/end` 500s on
     * whatever runs after the recorder call in the SAME transaction
     * (`liveClock->close()`, `recordLlmUsage`, `settleCompletionIfFinished()`,
     * `buildDirective()`), and `handleIssuePending()` — where the recorder
     * call is the LAST statement in its transaction — commits an already-
     * aborted transaction, which Postgres silently treats as a ROLLBACK:
     * a 201 response with NONE of that transaction's writes (participant
     * status, `started_at`, the `InterviewSession` row) actually persisted.
     *
     * `DB::transaction()`, called while `DB::transactionLevel() > 0`
     * already (true for every real caller above), issues a Postgres
     * SAVEPOINT instead of a real `BEGIN` — the SAME nested-transaction
     * mechanism `ScoreEvaluationJob::transitionParticipantToErrore()`
     * already relies on for its own state-changing write. On failure,
     * Laravel's own `rollBack()` issues `ROLLBACK TO SAVEPOINT` (recovering
     * the connection to a healthy state) and RE-THROWS — caught here,
     * logged, and swallowed exactly as before, but now with the outer
     * transaction's own health untouched: nothing this class's own
     * docblock promises ("never fail, retry, or roll back" the CALLER's
     * seam) changes for the caller.
     *
     * @param  array<string, mixed>|null  $data
     */
    private static function record(int $organizationId, int $participantId, string $type, ?array $data = null): void
    {
        try {
            DB::transaction(function () use ($organizationId, $participantId, $type, $data): void {
                TenantContextScope::runFor($organizationId, function () use ($participantId, $type, $data): void {
                    InterviewEvent::create([
                        'participant_id' => $participantId,
                        'type' => $type,
                        'occurred_at' => now(),
                        'data' => $data,
                    ]);
                });
            });
        } catch (Throwable $e) {
            Log::error('public-api: failed to record interview event', [
                'organization_id' => $organizationId,
                'participant_id' => $participantId,
                'type' => $type,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
