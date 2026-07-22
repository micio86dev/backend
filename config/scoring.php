<?php

declare(strict_types=1);

/**
 * Scoring Engine configuration (C9 — Scoring Engine).
 *
 * Keys:
 *   validity_threshold        — minimum reliability [0..1] for a competency to be counted valid.
 *                               Client must ratify before IT go-live (open decision D7).
 *   model_version             — pinned LLM model identifier; change triggers a new prompt_version bump.
 *                               Used by AnthropicLLMProvider as the default model.
 *   prompt_version            — semantic version string for the scoring prompt; bump on any prompt edit.
 *   gate.count_unscorable_against_total — when true (default), unscorable competencies are included
 *                               in the gate denominator (counted against the 90% threshold).
 *                               Set to false to exclude unscorable from denominator (client-ratifiable).
 *   anthropic                 — Anthropic Messages API config (C9 D7 resolved — no SDK).
 *
 * REQ: Scoring config (C9 Phase 1 + D7 LLM binding)
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Validity Threshold
    |--------------------------------------------------------------------------
    |
    | Minimum reliability value [0..1] required for a competency to be treated
    | as "valid" in the 90% completion gate.
    |
    | Formula: reliability = assessed_count / total_indicators (R-A strategy).
    | Default 0.5 means at least 50% of indicators must have assessable evidence.
    | Client must ratify this value before IT production scoring go-live.
    |
    */
    'validity_threshold' => (float) env('SCORING_VALIDITY_THRESHOLD', 0.5),

    /*
    |--------------------------------------------------------------------------
    | Model Version
    |--------------------------------------------------------------------------
    |
    | The pinned LLM model identifier recorded on every Evaluation row and
    | ai_requests row for traceability/determinism. Use the exact versioned ID,
    | never an alias (e.g. 'claude-haiku-4-5-20251001' not 'claude-haiku-4-5').
    |
    | Used by AnthropicLLMProvider as the default model when $options['model']
    | is not supplied by the caller.
    |
    */
    'model_version' => env('SCORING_MODEL_VERSION', 'claude-haiku-4-5-20251001'),

    /*
    |--------------------------------------------------------------------------
    | Prompt Version
    |--------------------------------------------------------------------------
    |
    | Semantic version string for the scoring prompt template. Bump this string
    | on ANY edit to the scoring prompt — enables per-Evaluation traceability.
    |
    */
    'prompt_version' => env('SCORING_PROMPT_VERSION', '1.0.0'),

    /*
    |--------------------------------------------------------------------------
    | Gate Policy
    |--------------------------------------------------------------------------
    |
    | count_unscorable_against_total:
    |   true  (default) — unscorable competencies (role_no_bars, anchor_translation_missing,
    |           llm_parse_error) are counted in the gate denominator and are NOT valid.
    |           They count AGAINST the 90% threshold. Safe/honest default.
    |   false — unscorable competencies are excluded from BOTH numerator and denominator.
    |           Flip to true to "ignore" unscorables in the gate. Client-ratifiable.
    |
    */
    'gate' => [
        'count_unscorable_against_total' => (bool) env('SCORING_GATE_COUNT_UNSCORABLE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anthropic API Configuration (C9 D7 — resolved, no SDK)
    |--------------------------------------------------------------------------
    |
    | Production LLMProvider calls the Anthropic Messages API directly via
    | Laravel's Http client — NO third-party SDK, NO D25 dependency to pin.
    |
    | api_key:         ANTHROPIC_API_KEY env var — NEVER hardcode. Must be set
    |                  in production and the ai-integration CI environment.
    | base_url:        Anthropic API base URL (override for staging/proxy).
    | version:         anthropic-version header sent on every request.
    | max_tokens:      Default max_tokens for scoring responses.
    | timeout_seconds: Http client read timeout per request.
    |
    */
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 2048),
        'timeout_seconds' => (int) env('ANTHROPIC_TIMEOUT', 60),
    ],

];
