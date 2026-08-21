<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `interview_session_live_periods` — one row per stretch a session held a
 * live provider session (interview-session-started-at, D1).
 *
 * THE CRUX of the change this migration belongs to: a resumed candidate
 * issues a NEW provider session on the SAME `interview_sessions` row, so one
 * `started_at`/`ended_at` pair cannot express two stretches without either
 * counting the abandonment gap between them (leave-alone) or discarding the
 * first stretch's billed minutes (reset). This table holds N stretches, one
 * row each, so `InterviewSession::liveSeconds()` can SUM closed periods
 * rather than subtract two columns.
 *
 * `provider_session_ref` is not decoration — it is the only handle by which
 * a period can ever be reconciled against the provider's own bill, and it
 * survives `single-session-interview` unchanged: a ref spanning several
 * competencies still sums to that ref's true lifetime across its periods.
 *
 * `closed_reason` ∈ {resume, end, error} answers "which stretches were cut
 * short by a reconnect?" — the first question anyone asks of a surprising
 * total.
 *
 * `ended_at IS NULL` ⇔ the stretch is still live. The partial UNIQUE index
 * below is the D5 invariant: at most one open period per session, enforced
 * by PostgreSQL rather than by discipline in one class — a double-open is
 * the one bug that would silently double the cost figure this table exists
 * to make trustworthy.
 *
 * REQ: interview-session "Recorded duration is accumulated live time, not
 *      wall-clock span"; "A live session records when it became live, at
 *      both sites that grant it"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_session_live_periods', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->foreignId('interview_session_id')
                ->constrained('interview_sessions')
                ->cascadeOnDelete();

            // The only key shared with the provider's own bill — neither
            // HeyGen nor Tavus exposes a per-session billed amount through
            // an API, so this ref is the sole reconciliation handle.
            $table->string('provider_session_ref')->nullable();

            $table->timestampTz('started_at');

            // NULL ⇔ the stretch is still live.
            $table->timestampTz('ended_at')->nullable();

            // {resume, end, error} — which of the three exits from in_corso
            // closed this stretch.
            $table->string('closed_reason')->nullable();

            $table->timestamps();

            // D22 org-lead composite index for tenant-scoped queries.
            $table->index(['organization_id', 'interview_session_id']);
        });

        // D5 invariant: at most one open period per session. Partial index —
        // the fluent builder cannot express a WHERE clause on an index, so
        // this is raw DDL, same pattern as
        // `avatar_templates_one_active_per_org` (2026_08_01_000001).
        //
        // Enforced here, not only in SessionLiveClock, because discipline in
        // one class is not an invariant: the next writer of a status
        // transition does not necessarily read that class, and a double-open
        // is the one bug that silently doubles the cost figure this change
        // exists to make trustworthy.
        DB::statement(
            'CREATE UNIQUE INDEX interview_session_live_periods_one_open_per_session
             ON interview_session_live_periods (interview_session_id)
             WHERE ended_at IS NULL'
        );
    }

    public function down(): void
    {
        // Genuinely reversible in the schema sense — it returns the schema
        // to a state the system previously occupied. Stated honestly: every
        // row this drops is a live-time observation that exists nowhere
        // else. A rollback after production traffic loses those minutes
        // permanently, and per D7 (no backfill) they cannot be reconstructed
        // from anything else in the schema.
        Schema::dropIfExists('interview_session_live_periods');
    }
};
