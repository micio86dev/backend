<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `interview_session_llm_usage` — one append-only cost row per billed
 * interview session (pluggable-conversation-llm PR P6a/P6b, design D5/D10).
 *
 * Mirrors `ai_requests` exactly on the point that matters: `created_at`
 * only, NO `updated_at` (`2026_07_22_000004_create_ai_requests_table.php:63-64`).
 * A cost record that can be edited is not a cost record — enforced by
 * `tests/Arch/Observability/LlmUsageAppendOnlyArchTest.php`.
 *
 * `interview_session_id` UNIQUE: written exactly once, via `firstOrCreate()`
 * (already race-safe — `Builder.php:710-717` delegates to `createOrFirst()`
 * after its initial read), which is what makes a double `/end` and the
 * later reconciliation sweep both no-ops against an already-billed session.
 *
 * Columns split three ways:
 *   - MEASURED facts: `turn_count`, `system_prompt_chars`, `participant_chars`,
 *     `avatar_chars`, `live_seconds` — read back from rows already persisted
 *     (`utterances`, `interview_session_live_periods`), never re-guessed.
 *   - DERIVED: `estimated_input_tokens`, `estimated_output_tokens`,
 *     `estimated_cost_usd` (decimal(12,6) — 2dp floors a whole campaign to
 *     zero, `AiRequestCostEstimator.php:47-49`), `estimation_method` (lets a
 *     future audio-aware method coexist with `chars4_context_resend_v1`
 *     with no migration).
 *   - `rate_card` jsonb: a SNAPSHOT of the rates actually applied. A later
 *     price edit in `llm_models` must never change an already-stored cost —
 *     the historical row is the source of truth for what it charged.
 *
 * `actual_input_tokens` / `actual_output_tokens` / `actual_cost_usd` are
 * PERMANENTLY NULL in `managed` mode. In this mode the PROVIDER (Tavus or
 * HeyGen) calls Google's Gemini endpoint directly — BEAI never sees the
 * request — and neither vendor reports token counts back to us. These
 * columns exist so a FUTURE `native_duplex` mode (BEAI calling the model
 * itself) can fill them with zero schema change; they are not dead weight,
 * they are a schema already shaped for a change this one does not make.
 *
 * EXEMPT from `PurgeExpiredDataCommand` by design: this table is an
 * aggregate with no subject matter (an int and a jsonb rate snapshot carry
 * no PII), and cost history must outlive the transcript purge it has
 * nothing to do with. Asserted by
 * `tests/Feature/ConversationLlm/LlmUsageSurvivesPurgeTest.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_session_llm_usage', function (Blueprint $table): void {
            $table->id();

            // D22 org-first composite index rule.
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->foreignId('interview_session_id')
                ->unique()
                ->constrained('interview_sessions')
                ->cascadeOnDelete();

            $table->unsignedInteger('turn_count');
            $table->unsignedInteger('system_prompt_chars')->nullable();
            $table->unsignedInteger('participant_chars');
            $table->unsignedInteger('avatar_chars');
            $table->unsignedInteger('live_seconds')->nullable();

            $table->unsignedBigInteger('estimated_input_tokens');
            $table->unsignedBigInteger('estimated_output_tokens');
            $table->decimal('estimated_cost_usd', 12, 6)->nullable();
            $table->string('estimation_method');

            // Snapshot of the rates actually applied — see class docblock.
            $table->jsonb('rate_card');

            // Permanently NULL in managed mode — see class docblock.
            $table->unsignedBigInteger('actual_input_tokens')->nullable();
            $table->unsignedBigInteger('actual_output_tokens')->nullable();
            $table->decimal('actual_cost_usd', 12, 6)->nullable();

            // Append-only: created_at only, no updated_at — exactly ai_requests.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'interview_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_session_llm_usage');
    }
};
