<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create `indicator_score_audits` (scoring-audit-jev P2, design D4).
 *
 * One row per indicator covered by a run — every judgeable and skipped
 * indicator gets a row, always (AD-4): absence of a row for an in-scope
 * indicator is never a legal outcome. Append-only in the same sense as
 * `indicator_score_audit_runs` above.
 *
 * `outcome_reason`, NOT `skip_reason` (design C-E): the column also carries
 * `unavailable` and `malformed` reasons, and a column literally named
 * `skip_reason` on an `unavailable` row would say something false about the
 * row it is on — `App\Enums\IndicatorFailureReason`'s own docblock draws the
 * identical distinction one grain up.
 *
 * The two equivalence CHECKs are written in the identical shape as
 * `indicator_scores_unassessable_reason_check`: an EQUIVALENCE, not an
 * implication, so a `judged` row with no probability and a `skipped` row
 * carrying one are BOTH rejected.
 *
 * `cascadeOnDelete` from BOTH parents (D4) is deliberate: an audit is
 * deleted when its subject indicator is purged (AD-2's retention
 * requirement) AND when its owning run is deleted, so no dangling row of
 * either kind is ever reachable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicator_score_audits', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->foreignId('audit_run_id')
                ->constrained('indicator_score_audit_runs')
                ->cascadeOnDelete();

            $table->foreignId('indicator_score_id')
                ->constrained('indicator_scores')
                ->cascadeOnDelete();

            // Closed vocabulary (AuditVerdictStatus) — enumerated below.
            $table->string('status', 16);

            $table->decimal('support_probability', 5, 4)->nullable();

            // All three raw Noul probabilities (relevance/calibration/
            // grounding), numeric-only JSON — AD-6's "probability and
            // machine reason codes only" holds literally.
            $table->jsonb('question_probabilities')->nullable();

            // UNCONSTRAINED at the DB by design (D4/C-E) — the vocabulary
            // may extend later without a migration, the same asymmetry
            // unassessable_reason's own migration states for itself.
            $table->string('outcome_reason', 48)->nullable();

            // Append-only: created_at only, no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['audit_run_id', 'indicator_score_id']);

            // D22 org-first composite index for tenant-scoped queries.
            $table->index(['organization_id', 'indicator_score_id']);
        });

        DB::statement(
            "ALTER TABLE indicator_score_audits ADD CONSTRAINT indicator_score_audits_status_check
             CHECK (status IN ('judged', 'unavailable', 'malformed', 'skipped'))"
        );

        DB::statement(
            'ALTER TABLE indicator_score_audits ADD CONSTRAINT indicator_score_audits_probability_check
             CHECK ((status = \'judged\') = (support_probability IS NOT NULL))'
        );

        DB::statement(
            'ALTER TABLE indicator_score_audits ADD CONSTRAINT indicator_score_audits_reason_check
             CHECK ((status = \'judged\') = (outcome_reason IS NULL))'
        );

        DB::statement(
            'ALTER TABLE indicator_score_audits ADD CONSTRAINT indicator_score_audits_probability_domain_check
             CHECK (support_probability IS NULL OR (support_probability >= 0 AND support_probability <= 1))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('indicator_score_audits');
    }
};
