<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create `evaluations` table (C9 — Scoring Engine).
 *
 * Root entity for a participant's BARS evaluation run.
 * One row per participant (UNIQUE participant_id) — C6 mints a distinct
 * participant_id per project/org so this constraint is globally unique.
 *
 * Design invariants (D1, D22):
 * - organization_id leads all composite indexes (D22 org-first rule).
 * - unique(participant_id) — globally unique; prevents duplicate scoring runs.
 * - (organization_id, participant_id) — performance index, NOT a second unique constraint.
 * - evaluated_at is nullable (null while status = processing; set on processing→completed|pending).
 * - retry_attempt: domain-retry flag for audit; job payload flag controls re-scoring logic.
 * - All FKs explicitly indexed.
 * - cascadeOnDelete on organization_id (org removal removes evaluations).
 * - cascadeOnDelete on participant_id (participant removal removes their evaluation).
 * - restrictOnDelete on framework_version_id (prevents orphaning versioning data).
 *
 * REQ: Evaluation schema (C9 D1)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->foreignId('participant_id')
                ->constrained('participants')
                ->cascadeOnDelete();

            // Job execution status: processing → completed | pending.
            // 'pending' is an Evaluation sub-state, NOT a participant state.
            $table->string('status')->default('processing');

            $table->foreignId('framework_version_id')
                ->constrained('framework_versions')
                ->restrictOnDelete();

            // Pinned at job START for traceability/determinism.
            $table->string('model_version');
            $table->string('prompt_version');

            // Set on processing→completed|pending transition; null while processing.
            $table->timestamp('evaluated_at')->nullable();

            // Domain-retry audit flag (C9 D10 RT-B); read from job PAYLOAD for guard logic.
            $table->boolean('retry_attempt')->default(false);

            $table->timestamps();

            // SINGLE unique constraint on participant_id (globally unique scoring run).
            $table->unique('participant_id');

            // Performance index: org-first composite (D22); NOT a second unique.
            $table->index(['organization_id', 'participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluations');
    }
};
