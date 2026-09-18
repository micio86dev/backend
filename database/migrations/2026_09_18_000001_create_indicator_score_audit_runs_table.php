<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create `indicator_score_audit_runs` (scoring-audit-jev P2, design D4).
 *
 * One row per operator-triggered audit invocation over one evaluation.
 * Append-only in the `ai_requests` sense: `created_at` only, `useCurrent()`,
 * no `updated_at`, no UPDATE path in business logic — enforced by
 * `tests/Arch/Audit/AuditAppendOnlyArchTest.php` (P2.13).
 *
 * `indicators_malformed` exists because the proposal's three-term
 * reconciliation (`total = judged + skipped + unavailable`) fails on any run
 * containing one malformed verdict — see design.md C-D. The identity is
 * enforced below as a CHECK, not only by a test, because the run row is
 * written exactly once, at the end (D6), with every counter already known.
 *
 * Both equivalence CHECKs below are written in the identical shape as
 * `indicator_scores_unassessable_reason_check`
 * (2026_08_25_000002_add_unassessable_reason_to_indicator_scores.php:59-62):
 * an EQUIVALENCE, not an implication, so a `completed` row carrying a
 * `failure_reason` is rejected exactly as readily as a `partial`/`failed` row
 * with none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicator_score_audit_runs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->foreignId('evaluation_id')
                ->constrained('evaluations')
                ->cascadeOnDelete();

            $table->foreignId('requested_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Closed vocabulary (AuditRunStatus) — enumerated below at the DB.
            $table->string('status', 16);

            // UNCONSTRAINED at the DB by design (D4) — no run-level reason
            // enum exists yet (P3a/P3b introduce values such as
            // 'judge_unavailable'/'job_killed'), so the vocabulary may extend
            // without a migration, mirroring unassessable_reason's own
            // stated policy one grain up.
            $table->string('failure_reason', 64)->nullable();

            $table->unsignedInteger('indicators_total');
            $table->unsignedInteger('indicators_judged');
            $table->unsignedInteger('indicators_skipped');
            $table->unsignedInteger('indicators_unavailable');
            $table->unsignedInteger('indicators_malformed'); // C-D

            $table->unsignedInteger('input_tokens');
            $table->unsignedInteger('output_tokens');

            // Own meter (D8) — NULL when the judge model is unpriced, never 0.0.
            $table->decimal('estimated_cost_usd', 12, 6)->nullable();

            $table->unsignedInteger('latency_ms');

            // The EXACT vendor model id recorded verbatim on every run — an
            // alias would silently repoint and the judgment history would
            // stop meaning anything (D13).
            $table->string('judge_model_version', 64);

            // Semver of the audit question set (Noul questions), bumped on
            // ANY edit — the scoring prompt_version idiom, one grain over.
            $table->string('audit_prompt_version', 32);

            // Append-only: created_at only, no updated_at.
            $table->timestamp('created_at')->useCurrent();

            // D22 org-first composite index for tenant-scoped queries.
            $table->index(['organization_id', 'evaluation_id', 'created_at']);
        });

        DB::statement(
            "ALTER TABLE indicator_score_audit_runs ADD CONSTRAINT indicator_score_audit_runs_status_check
             CHECK (status IN ('completed', 'partial', 'failed'))"
        );

        DB::statement(
            'ALTER TABLE indicator_score_audit_runs ADD CONSTRAINT indicator_score_audit_runs_failure_reason_check
             CHECK ((status <> \'completed\') = (failure_reason IS NOT NULL))'
        );

        // Four-term coverage identity (C-D) — the DB's own reconciliation,
        // not only a test's.
        DB::statement(
            'ALTER TABLE indicator_score_audit_runs ADD CONSTRAINT indicator_score_audit_runs_coverage_check
             CHECK (indicators_total = indicators_judged + indicators_skipped
                                      + indicators_unavailable + indicators_malformed)'
        );

        DB::statement(
            'ALTER TABLE indicator_score_audit_runs ADD CONSTRAINT indicator_score_audit_runs_cost_check
             CHECK (estimated_cost_usd IS NULL OR estimated_cost_usd >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('indicator_score_audit_runs');
    }
};
