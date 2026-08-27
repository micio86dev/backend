<?php

declare(strict_types=1);

/**
 * Pluggable Conversation LLM configuration (pluggable-conversation-llm,
 * managed mode).
 *
 * Keys:
 *   timeout_seconds     — per-attempt HTTP timeout for GeminiKeyValidator's
 *                         probe request against the vendor endpoint.
 *   validation_retries  — number of ADDITIONAL attempts on a transport
 *                         failure (timeout / connection exception) only.
 *
 * REQ: conversation-llm "Credential validation returns a stable code, never
 *      the vendor's prose, and cannot become a key-testing oracle"
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Validation Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Per-attempt HTTP timeout for GeminiKeyValidator's probe request
    | (`POST {base_url}chat/completions`, max_tokens=1) against the live
    | Google Gemini OpenAI-compatible endpoint.
    |
    | Measured 2026-08-26 against the live endpoint with a VALID key —
    | 5 successful runs: 859ms / 3906ms / 4129ms / 4717ms / 6997ms, plus one
    | hung run that never returned (45061ms, ConnectionException). 15s sits
    | comfortably above the 7.0s worst observed success and well short of the
    | 45s hung tail — see GeminiKeyValidator's class docblock for the full
    | measurement table. Do NOT lower this back toward 8s: at 8s a large
    | share of genuinely valid keys were observed classifying as
    | `unreachable` (median latency alone is ~4s, and successes reach ~7s).
    |
    */
    'timeout_seconds' => (int) env('CONVERSATION_LLM_VALIDATION_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Validation Retries
    |--------------------------------------------------------------------------
    |
    | Number of ADDITIONAL attempts GeminiKeyValidator makes after a
    | TRANSPORT failure (timeout or connection exception) ONLY. Never
    | retried: a 401/403 (`invalid_key` is deterministic — retrying a dead
    | key is pointless and doubles the oracle surface) or a 429
    | (`rate_limited` — retrying immediately makes rate limiting worse).
    |
    | Default 1 — "exactly ONE retry" per the ratified measurement-driven fix.
    |
    */
    'validation_retries' => (int) env('CONVERSATION_LLM_VALIDATION_RETRIES', 1),

    /*
    |--------------------------------------------------------------------------
    | Per-template cost forecast (pluggable-conversation-llm PR P6b, design D10)
    |--------------------------------------------------------------------------
    |
    | Reference parameters for `AvatarTemplateResource.llm.estimated_cost_usd_per_interview`
    | — a TOTAL for one reference interview, computed by the SAME
    | `ConversationLlmUsageEstimator` the real `/end` write uses, over
    | SYNTHETIC turns built from these constants rather than real
    | transcript rows.
    |
    | Deliberately NOT a $/minute figure: input tokens grow QUADRATICALLY in
    | turn count (the whole conversation history is re-sent every turn), so
    | a per-minute number misstates cost at any other interview length. A
    | shape — "≈$X for a typical N-minute, M-turn interview" — survives
    | being reasoned about; a per-minute rate invites an operator to
    | multiply it by session length and be confidently wrong.
    |
    | `reference_minutes` / `reference_turns`: a typical BEAI interview
    | (one competency asks a handful of adaptive follow-up questions; a
    | full assessment covers several competencies in one sitting).
    | `reference_system_prompt_chars`: the composed BARS system prompt is
    | re-sent on EVERY turn and is the LARGEST term in the cost formula —
    | ~3000 chars reflects a role's full competency framework context.
    | `reference_participant_chars_per_turn` / `reference_avatar_chars_per_turn`:
    | a spoken conversational turn, not a written paragraph — short.
    |
    */
    'forecast' => [
        'reference_minutes' => (int) env('CONVERSATION_LLM_FORECAST_MINUTES', 15),
        'reference_turns' => (int) env('CONVERSATION_LLM_FORECAST_TURNS', 60),
        'reference_system_prompt_chars' => (int) env('CONVERSATION_LLM_FORECAST_SYSTEM_PROMPT_CHARS', 3000),
        'reference_participant_chars_per_turn' => (int) env('CONVERSATION_LLM_FORECAST_PARTICIPANT_CHARS', 150),
        'reference_avatar_chars_per_turn' => (int) env('CONVERSATION_LLM_FORECAST_AVATAR_CHARS', 200),
    ],

];
