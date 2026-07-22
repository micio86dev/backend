<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create `indicator_scores` table (C9 — Scoring Engine).
 *
 * One row per BARS indicator per competency result. Maps to `behaviors[]{}`
 * in the sample report (esempio-report-valutazione.json).
 *
 * Design invariants (D1, D22):
 * - organization_id leads all composite indexes (D22 org-first rule).
 * - score: smallint — values in {1, 3, 5, -1} (D4 domain; validated by IndicatorValidator).
 * - position: unsignedSmallInt — zero-based index; used for position-based mapping (D4/FIX-8).
 * - excerpts: json — original LLM excerpt array (persisted verbatim, not normalized).
 * - explanation: text — LLM-provided behavioral rationale.
 * - cascadeOnDelete on organization_id and competency_result_id.
 *
 * REQ: IndicatorScore schema (C9 D1)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicator_scores', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->foreignId('competency_result_id')
                ->constrained('competency_results')
                ->cascadeOnDelete();

            // Zero-based position within the BARS indicator list for this competency.
            // Used for array-position mapping between LLM response and catalog (D4/FIX-8).
            $table->unsignedSmallInteger('position');

            // Canonical BARS indicator text at pinned framework_version_id in project locale.
            $table->string('indicator_text');

            // Discrete score from LLM: {1, 3, 5} = assessed; -1 = unassessable sentinel.
            $table->smallInteger('score');

            // LLM-provided behavioral rationale for the score.
            $table->text('explanation');

            // Verbatim excerpts from the transcript as returned by the LLM.
            // Array; [] (empty) is valid for score = -1 (CC2).
            $table->json('excerpts');

            $table->timestamps();

            // D22 org-first composite index for tenant-scoped queries.
            $table->index(['organization_id', 'competency_result_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicator_scores');
    }
};
