<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create `competency_results` table (C9 — Scoring Engine).
 *
 * One row per competency per evaluation. Maps to `COL{score, reliability}` in
 * the sample report (esempio-report-valutazione.json).
 *
 * Design invariants (D1, D22):
 * - organization_id leads all composite indexes (D22 org-first rule).
 * - unique(evaluation_id, competency_code) — one result per competency per evaluation.
 * - score: numeric(5,2) nullable — null for unscored/unscorable competencies.
 * - reliability: numeric(5,4) — stored as [0..1] ratio, rendered as % at API boundary.
 * - unscorable_reason: nullable string; domain values: role_no_bars, anchor_translation_missing,
 *   llm_parse_error. Stored as string (not enum cast) for v1 stability.
 * - cascadeOnDelete on organization_id and evaluation_id.
 *
 * REQ: CompetencyResult schema (C9 D1)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competency_results', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->foreignId('evaluation_id')
                ->constrained('evaluations')
                ->cascadeOnDelete();

            // BARS competency code (e.g. 'COL', 'PRS', 'SLF').
            $table->string('competency_code');

            // Mean of assessed indicator scores, rounded to 2dp (PHP_ROUND_HALF_UP).
            // Null for unscorable competencies or all-indicators-(-1) case (CC2).
            $table->decimal('score', 5, 2)->nullable();

            // Reliability ratio [0..1] stored with 4 decimal places.
            // Rendered as integer percentage at API/webhook boundary.
            $table->decimal('reliability', 5, 4)->default(0.0);

            // True if reliability ≥ validity_threshold (V-A predicate).
            $table->boolean('valid')->default(false);

            // Set when competency is skipped without scoring.
            // Domain values: role_no_bars | anchor_translation_missing | llm_parse_error
            $table->string('unscorable_reason')->nullable();

            $table->timestamps();

            // Exactly one result per competency per evaluation.
            $table->unique(['evaluation_id', 'competency_code']);

            // D22 org-first composite index for tenant-scoped queries.
            $table->index(['organization_id', 'evaluation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competency_results');
    }
};
