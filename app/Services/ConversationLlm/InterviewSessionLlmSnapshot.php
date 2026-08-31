<?php

declare(strict_types=1);

namespace App\Services\ConversationLlm;

use App\Enums\LlmBindingStatus;
use App\Models\InterviewSession;
use App\Support\AvatarTemplates\ActiveTemplateResolver;

/**
 * Snapshots the conversation-LLM binding onto an `InterviewSession` at
 * `issue()` time (pluggable-conversation-llm PR P6a, design D5).
 *
 * `issue()` IS called again on resume (`InterviewController.php:690`/`:789`)
 * — a resumed session re-enters this code with a snapshot already taken.
 * The codebase's own `started_at ??= now()` idiom (`:748`/`:807`) is the
 * precedent this mirrors, called from inside the SAME short DB transaction,
 * next to that write.
 *
 * Field-by-field re-entry rule (design D5 table):
 *   - `avatar_template_id` / `llm_model_key` — plain write-once (`??=`).
 *     The session is attributed to the template/model it STARTED under;
 *     activating a different template mid-session must not rewrite turns
 *     already spoken.
 *   - `llm_binding_status` — write-once, then DOWNGRADE-ONLY. May fall to a
 *     non-billable value on a later resolve, never climb back to `applied`.
 *     Under-report, never over-report (design D0).
 *   - `system_prompt_chars` — write-once AND never overwritten FROM a null.
 *     The degraded RESUME path (`InterviewController.php:206-213`)
 *     deliberately fabricates a null system prompt; a naive re-stamp would
 *     destroy a previously recorded good value — and `P` is the LARGEST
 *     term in the cost estimator's `c_t` (design D10) because it is
 *     re-sent every turn.
 *
 * `avatar_template_id` comes from `ActiveTemplateResolver` (the template
 * actually active for this session's provider, whether or not it carries a
 * usable LLM binding); `llm_model_key` / `llm_binding_status` come from
 * `LlmBindingResolver`, which is pure DB (never HTTP) — the `applied` decision
 * must be knowable from what was written down at template-save time, not
 * from a live provider call at session-issue time (design D0).
 */
final class InterviewSessionLlmSnapshot
{
    public function __construct(
        private readonly ActiveTemplateResolver $templates,
        private readonly LlmBindingResolver $bindings,
    ) {}

    /**
     * Mutates $session's snapshot columns in place. Caller is responsible
     * for `save()`-ing inside the same short DB transaction as `started_at`.
     *
     * @param  string|null  $systemPrompt  The composed system prompt for THIS
     *                                     issue() call, or null on the degraded RESUME path
     *                                     (composition failed — do NOT fabricate prompt text).
     */
    public function stamp(InterviewSession $session, ?string $systemPrompt): void
    {
        $template = $this->templates->resolve($session->provider, $session->project_id);

        // Plain write-once.
        $session->avatar_template_id ??= $template?->id;

        $resolvedStatus = $template === null
            ? LlmBindingStatus::Unbound
            : $this->bindings->resolveStatus($template);

        $binding = $template === null ? null : $this->bindings->resolve($template);

        $session->llm_model_key ??= $binding?->modelKey;

        // Write-once, then DOWNGRADE-ONLY (design D5). Under-report, never
        // over-report: once non-applied, never climbs back to applied.
        if ($session->llm_binding_status === null) {
            $session->llm_binding_status = $resolvedStatus->value;
        } elseif ($resolvedStatus !== LlmBindingStatus::Applied) {
            $session->llm_binding_status = $resolvedStatus->value;
        }

        // Write-once AND never written FROM a null.
        if ($systemPrompt !== null && $session->system_prompt_chars === null) {
            $session->system_prompt_chars = mb_strlen($systemPrompt);
        }
    }
}
