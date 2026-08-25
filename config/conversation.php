<?php

declare(strict_types=1);

/**
 * Conversation Engine configuration (C8 — Interview Conversation).
 *
 * Keys:
 *   prompt_version    — conversation prompt template version, stamped by SystemPromptComposer
 *                       on every composed prompt. Distinct lifecycle from scoring.prompt_version —
 *                       do NOT reuse config/scoring.php. Bump on ANY template change.
 *   followup_budget   — default follow-up budget per competency (max N per competency).
 *                       N=4 RATIFIED 2026-08-25, closing the C8 OQ-1 that had been open
 *                       since the conversation engine shipped. A per-project override
 *                       arrives with the `project-followup-budget` change.
 *   min_questions     — minimum questions (opening included) before the avatar may speak
 *                       the closing phrase. CLAMPED by SystemPromptComposer to what the
 *                       budget permits — a minimum above `budget + 1` would be
 *                       unsatisfiable and would strand the competency at its session cap.
 *   nudge_min_chars   — default minimum character threshold for a "sufficient" answer.
 *                       null means nudge is disabled by default at the platform level;
 *                       each Project may override via project.nudge_min_chars.
 *
 * REQ: config/conversation.php (C8 RV-4)
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Conversation Prompt Version
    |--------------------------------------------------------------------------
    |
    | Template version string stamped by SystemPromptComposer onto every
    | composed system prompt. Bump this string on ANY edit to the conversation
    | prompt template — enables per-interview traceability.
    |
    | Distinct from scoring.prompt_version — conversation versioning must be
    | wired independently of scoring (KD-3 mirrors C9 discipline).
    |
    */
    'prompt_version' => env('CONVERSATION_PROMPT_VERSION', 'conv-2026-08-25'),

    /*
    |--------------------------------------------------------------------------
    | Follow-Up Budget
    |--------------------------------------------------------------------------
    |
    | Default maximum follow-up questions the avatar may ask per competency.
    | RATIFIED 2026-08-25 at 4 (was 2, provisional since C8). Total questions per
    | competency is this value PLUS the opening question, which is not a follow-up.
    |
    | A nullable per-project override follows as `project-followup-budget`;
    | SystemPromptComposer already reads whatever budget it is handed, so nothing
    | in the composer changes when that lands.
    |
    */
    'followup_budget' => (int) env('CONVERSATION_FOLLOWUP_BUDGET', 4),

    /*
    |--------------------------------------------------------------------------
    | Minimum Question Count
    |--------------------------------------------------------------------------
    |
    | The avatar must not speak the closing phrase before asking at least this
    | many questions in a competency, counting the opening question.
    |
    | SystemPromptComposer CLAMPS this to `min(value, budget + 1)`. Do not rely on
    | configuration discipline to keep the two in agreement: a minimum the budget
    | cannot satisfy is an instruction the avatar can never obey, and the observed
    | consequence is the competency running to its session cap and HeyGen killing
    | it with MAX_DURATION_REACHED.
    |
    */
    'min_questions' => (int) env('CONVERSATION_MIN_QUESTIONS', 4),

];
