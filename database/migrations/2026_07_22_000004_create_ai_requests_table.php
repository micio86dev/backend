<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create `ai_requests` table (C9 — Scoring Engine).
 *
 * Append-only audit log for every LLM call made during scoring.
 * Never updated after INSERT; no `updated_at` timestamp.
 *
 * Design invariants (D1, D22):
 * - organization_id leads all composite indexes (D22 org-first rule).
 * - evaluation_id: nullable FK (nullable because the column schema allows null,
 *   but under normal operation evaluation_id is always set before the first LLM
 *   call — the Evaluation row is created at job START in 'processing' status).
 * - Append-only: no `updated_at`, no update() in business logic.
 * - Unscorable competencies make NO LLM call → no ai_requests row.
 * - cascadeOnDelete on organization_id; cascadeOnDelete on evaluation_id
 *   (removing the evaluation removes its LLM audit trail).
 *
 * REQ: ai_requests schema (C9 D1)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            // Nullable: schema allows null, but evaluation_id is always known at job time.
            $table->foreignId('evaluation_id')
                ->nullable()
                ->constrained('evaluations')
                ->cascadeOnDelete();

            // BARS competency code that this LLM call was scoring.
            $table->string('competency_code');

            // Exact model identifier used for the call.
            $table->string('model');

            // Prompt version string from config('scoring.prompt_version').
            $table->string('prompt_version');

            // Token usage from the LLM response.
            $table->unsignedInteger('input_tokens');
            $table->unsignedInteger('output_tokens');

            // LLM finish reason (e.g. 'stop', 'length', 'error').
            $table->string('finish_reason')->nullable();

            // Provider-reported latency in milliseconds.
            $table->unsignedInteger('latency_ms');

            // Append-only: created_at only, no updated_at.
            $table->timestamp('created_at')->useCurrent();

            // D22 org-first composite index for tenant-scoped queries.
            $table->index(['organization_id', 'evaluation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
