<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create `interview_sessions` table (C7a — Interview Session Mechanics).
 *
 * One row per competency per participant — enforced by the unique constraint
 * below. One session = one competency, for the WHOLE life of that competency:
 * an errored competency re-offered to the candidate reuses this row rather than
 * inserting a second, and `error_count` is what records how many attempts it has
 * spent. (It said "one row per competency ATTEMPT" until the re-offer shipped;
 * a row can hold more than one.)
 *
 * Design invariants:
 * - organization_id: cascadeOnDelete (org deletion cascades to sessions).
 * - participant_id: cascadeOnDelete (participant deletion removes their sessions).
 * - project_id: restrictOnDelete (belt-and-suspenders: accidental project hard-delete blocked).
 * - framework_version_id: copied from project at creation; restrictOnDelete.
 * - status: LOCKED enum {pending,in_corso,completed,timeout,skipped,error}; default 'pending'.
 * - UNIQUE(participant_id, competency_code): idempotent on /start.
 * - Composite indexes lead with organization_id (D22 org-lead rule).
 * - started_at / ended_at: timestampTz (server-set; nullable).
 * - question_index: 0-based ordinal. Invariant: question_index ==
 *   project_competencies.position of that session's competency within the
 *   session's project — never derived by arithmetic (interview-question-index-offset,
 *   D6). Frozen at session creation; recomputed to CURRENT position by the
 *   `2026_08_21_160000_recompute_interview_session_question_index` backfill for any
 *   row already persisted before that fix shipped.
 *
 * REQ: InterviewSession tenant model + schema (C7a)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_sessions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->foreignId('participant_id')
                ->constrained('participants')
                ->cascadeOnDelete();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->restrictOnDelete();

            // Invariant: question_index == project_competencies.position of this
            // session's competency (0-based) — never position - 1. See class
            // docblock (interview-question-index-offset, D6).
            $table->integer('question_index');

            // The BARS competency code (e.g. 'PRS', 'STG').
            $table->string('competency_code');

            // Copied from project.framework_version_id at session creation — immutable thereafter.
            $table->foreignId('framework_version_id')
                ->constrained('framework_versions')
                ->restrictOnDelete();

            // Provider name: 'heygen' | 'tavus'.
            $table->string('provider');

            // Opaque provider session reference for teardown/reconciliation.
            $table->string('provider_session_ref')->nullable();

            // LOCKED status enum — transitions defined in design.md.
            // {pending,in_corso,completed,timeout,skipped,error}
            $table->string('status')->default('pending');

            // Reason set at end: {completed,timeout,skipped,error}.
            $table->string('ended_reason')->nullable();

            // Server-set lifecycle timestamps.
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('ended_at')->nullable();

            $table->timestamps();

            // Idempotent /start: one session per participant per competency.
            $table->unique(['participant_id', 'competency_code']);

            // D22 org-lead composite indexes for tenant-scoped queries.
            $table->index(['organization_id', 'participant_id']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_sessions');
    }
};
