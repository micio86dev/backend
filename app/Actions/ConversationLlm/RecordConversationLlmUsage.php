<?php

declare(strict_types=1);

namespace App\Actions\ConversationLlm;

use App\Enums\LlmBindingStatus;
use App\Models\InterviewSession;
use App\Models\InterviewSessionLlmUsage;
use App\Services\ConversationLlm\ConversationLlmUsageEstimator;

/**
 * The single write path for `interview_session_llm_usage`
 * (pluggable-conversation-llm PR P6b, design D10).
 *
 * Shared by BOTH callers so they run the IDENTICAL computation:
 *   - `InterviewController::end()` — the normal path, inside the same
 *     explicit DB transaction that stamps `ended_at`.
 *   - `beai:reconcile-llm-usage` — the daily sweep for a session that ended
 *     via server error or was never `/end`-ed at all. NOT an approximation
 *     of the `/end` computation; every input this reads
 *     (`utterances`, `interview_session_live_periods`, `system_prompt_chars`,
 *     the `llm_models` rate card) is already persisted, so the sweep gets
 *     the same number `/end` would have.
 *
 * No usage row when `llm_binding_status !== 'applied'` — a session that ran
 * on the vendor's own default LLM gets no Gemini cost row (design D0).
 *
 * `firstOrCreate()` on the UNIQUE `interview_session_id` — already
 * race-safe (`Builder.php:710-717` delegates to `createOrFirst()` after its
 * initial read) — is what makes a double `/end`, or a late `/end` racing
 * this sweep, both no-ops. Do NOT replace with `createOrFirst()`; checked,
 * see design D10.
 */
final class RecordConversationLlmUsage
{
    public function __construct(
        private readonly ConversationLlmUsageEstimator $estimator,
    ) {}

    public function __invoke(InterviewSession $session): ?InterviewSessionLlmUsage
    {
        if ($session->llm_binding_status !== LlmBindingStatus::Applied->value) {
            return null;
        }

        $data = $this->estimator->estimate($session);

        return InterviewSessionLlmUsage::firstOrCreate(
            ['interview_session_id' => $session->id],
            $data,
        );
    }
}
