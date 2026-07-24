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
 *                       N=2 is PROVISIONAL (OQ-1 — client ratification required).
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
    'prompt_version' => env('CONVERSATION_PROMPT_VERSION', 'conv-2026-07-23'),

    /*
    |--------------------------------------------------------------------------
    | Follow-Up Budget
    |--------------------------------------------------------------------------
    |
    | Default maximum follow-up questions the avatar may ask per competency.
    | PROVISIONAL (OQ-1) — client ratification required before production go-live.
    |
    */
    'followup_budget' => (int) env('CONVERSATION_FOLLOWUP_BUDGET', 2),

];
