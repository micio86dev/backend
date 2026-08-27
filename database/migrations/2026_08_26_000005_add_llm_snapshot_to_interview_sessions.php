<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Four snapshot columns on `interview_sessions`, all nullable and additive
 * (pluggable-conversation-llm PR P6a, design D5).
 *
 * These are a SNAPSHOT of the conversation-LLM binding as it stood the
 * moment the session was first issued to a provider — never re-derived from
 * the template's CURRENT state. `InterviewSession` already refuses that once
 * for `framework_version_id` ("copied from project at session creation;
 * NEVER re-derived", `InterviewSession.php:33-34`); this is a weaker second
 * parallel (`provider` is copied at creation too but carries no such
 * comment), not a second citation of the same rule.
 *
 * `avatar_template_id` — nullable FK, `nullOnDelete()`. Unlike
 * `llm_credential_id`/`llm_model_id` on `avatar_templates` (`restrictOnDelete`,
 * because a LIVE binding must not silently lose its target), a historical
 * session's attribution must survive the template being deleted later —
 * `AvatarTemplateController::destroy()` is a normal operator action, and a
 * FK that blocked it would let old interview history hold a live delete
 * hostage. `llm_model_key` (the STRING, not `llm_model_id`) is what keeps
 * the cost line readable even after the FK goes null.
 *
 * `llm_model_key` — the exact vendor model string (`llm_models.key`), NOT an
 * FK. A snapshot is a string by nature: an FK resolves to the CURRENT row,
 * reintroducing the same re-derivation bug one layer down.
 *
 * `llm_binding_status` — `applied` | `unbound` | `degraded`
 * (`App\Enums\LlmBindingStatus`). Write-once, THEN DOWNGRADE-ONLY — see
 * `InterviewSessionLlmSnapshot::stamp()`. Kept a plain string column (no DB
 * CHECK), mirroring `avatar_templates.llm_sync_status`'s own reasoning: one
 * ALTER of application code costs less than a type migration when a new
 * status is added.
 *
 * `system_prompt_chars` — `mb_strlen()` of the composed system prompt at
 * issue() time. The composed prompt itself is NOT persisted anywhere today
 * (`InterviewController.php` builds it, only `prompt_version` survives); this
 * int is the load-bearing new capture, and it is the LARGEST term in the
 * cost estimator's `c_t` (design D10) because it is re-sent on every turn.
 * Write-once AND never overwritten from a null — the degraded RESUME path
 * deliberately fabricates a null system prompt, and a naive re-stamp would
 * write that null over a perfectly good value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interview_sessions', function (Blueprint $table): void {
            $table->foreignId('avatar_template_id')->nullable()
                ->after('framework_version_id')
                ->constrained('avatar_templates')->nullOnDelete();

            $table->string('llm_model_key')->nullable()->after('avatar_template_id');
            $table->string('llm_binding_status')->nullable()->after('llm_model_key');
            $table->unsignedInteger('system_prompt_chars')->nullable()->after('llm_binding_status');
        });
    }

    public function down(): void
    {
        Schema::table('interview_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('avatar_template_id');
            $table->dropColumn(['llm_model_key', 'llm_binding_status', 'system_prompt_chars']);
        });
    }
};
