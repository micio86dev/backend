<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create `participants` table (C6 — Participant + SSO Ingress).
 *
 * Stores candidate participants for a given project. Each row represents one
 * candidate instance for one project. The opaque candidate_ref is echoed
 * unchanged in every webhook (GDPR/integration contract).
 *
 * Design invariants:
 * - organization_id: cascadeOnDelete — deleting an org removes all participants.
 * - project_id: cascadeOnDelete — deleting a project removes its participants.
 * - UNIQUE(project_id, candidate_ref): idempotent upsert target at exchange.
 * - organization_id FIRST in composites (D22 org-lead rule).
 * - started_at / completed_at: timestampTz (timezone-aware, best practice).
 * - No deleted_at (no SoftDeletes in C6; GDPR concern deferred to C13).
 * - language nullable in schema but always set non-null by exchange code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participants', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            // Opaque SSO identifier — stored VERBATIM; echoed in all webhooks.
            $table->string('candidate_ref');

            // REQUIRED at exchange; NOT NULL at DB level.
            $table->string('display_name');

            // == project.role_code for standard; null for potential assessments.
            $table->string('role_code')->nullable();

            // Always set to a supported locale at exchange (never null in C6).
            // Declared nullable so schema is forward-compatible if future paths skip it.
            $table->string('language')->nullable();

            // Candidate lifecycle: in_attesa→in_corso→in_valutazione→completato|errore
            $table->string('status')->default('in_attesa');

            // Timezone-aware lifecycle timestamps (C6 only sets in_attesa; C7/C9 update these).
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            $table->timestamps();

            // Idempotent upsert target — ON CONFLICT(project_id, candidate_ref) at exchange.
            $table->unique(['project_id', 'candidate_ref']);

            // D22 org-first composite indexes for tenant-scoped queries.
            $table->index(['organization_id', 'project_id']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participants');
    }
};
