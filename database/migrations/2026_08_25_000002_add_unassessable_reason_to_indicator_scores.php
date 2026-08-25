<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * indicator_scores gains unassessable_reason (C13, scoring-failure-containment
 * D1/D7 — product owner override, 0.4 in tasks.md: named `unassessable_reason`
 * at every layer, never `reason`, never `failure_reason`).
 *
 * One ADDITIVE, NULLABLE column — the indicator-grain sibling of
 * `ai_requests.failure_reason` (call grain) and `competency_results.
 * unscorable_reason` (competency grain). UNCONSTRAINED to specific values —
 * no value-enumerating CHECK — so the vocabulary MAY extend later without a
 * migration (scoring-engine spec's "Indicator Validation-Failure Reason
 * Vocabulary" requirement); only a PRESENCE-based equivalence CHECK exists,
 * mirroring `ai_requests_failure_reason_check`'s shape exactly (C-A).
 *
 * Backfill is a STATEMENT OF FACT, not a guess: before this change (and
 * before B2b's per-indicator isolation lands), a validation failure discarded
 * the WHOLE competency and persisted ZERO IndicatorScore rows — every
 * existing `score = -1` row was therefore written because the LLM ITSELF
 * declared no assessable evidence, i.e. model_declared by construction
 * (mirrors the identical reasoning at 2026_07_31_000001_...:44-50 for
 * `ai_requests.success`).
 *
 * IMPORTANT — deploy-ordering note: this migration's equivalence CHECK
 * requires the job's write path (App\Jobs\ScoreEvaluationJob, wired in the
 * companion PR B2b) to persist `unassessable_reason` on EVERY `score = -1`
 * insert, including the pre-existing model_declared case. If this migration
 * runs in an environment where the job still inserts `IndicatorScore` rows
 * without `unassessable_reason` (i.e. before B2b's write-path change lands),
 * ANY future model-declared `-1` insert violates this CHECK and the scoring
 * job fails hard. B2a and B2b are therefore NOT independently deployable to
 * production despite being separate reviewable commits in the PR chain —
 * they must ship in the same release.
 *
 * No data precondition on rollback: down() drops the CHECK then the column;
 * dropping a column cannot violate a constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('indicator_scores', function (Blueprint $table): void {
            $table->string('unassessable_reason', 32)->nullable()->after('score');
        });

        // Statement of fact, not a guess (see class docblock): every existing
        // score = -1 row predates per-indicator isolation.
        DB::table('indicator_scores')
            ->where('score', -1)
            ->update(['unassessable_reason' => 'model_declared']);

        DB::statement(
            'ALTER TABLE indicator_scores ADD CONSTRAINT indicator_scores_unassessable_reason_check
             CHECK ((score = -1) = (unassessable_reason IS NOT NULL))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE indicator_scores DROP CONSTRAINT IF EXISTS indicator_scores_unassessable_reason_check');

        Schema::table('indicator_scores', function (Blueprint $table): void {
            $table->dropColumn('unassessable_reason');
        });
    }
};
