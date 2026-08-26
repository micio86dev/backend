<?php

declare(strict_types=1);

use App\Enums\LlmCapability;

/**
 * The verified conversation-LLM model catalog (pluggable-conversation-llm
 * PR P1, design D1 / C-A / C-B / C-C).
 *
 * A committed PHP array, not a generated one — every `key` here is the EXACT
 * vendor model string, verified to exist and sent VERBATIM to Tavus
 * (`layers.llm.model`) and HeyGen (`/v1/llm-configurations`). Do not add
 * `gemini-3-pro` or `gemini-3-flash` — neither exists as a vendor model id
 * (both were superseded by their `-preview` siblings; see design.md C-A).
 *
 * Every rate is nullable and OMITTED (not zeroed) where Google does not
 * publish it — `gemini-3.1-pro-preview`'s audio rate is genuinely
 * unpublished, and `audio_tokens_per_second` is published for neither Live
 * model seeded here (design.md C-C). Consumed by
 * `Database\Seeders\LlmModelRegistrySeeder` and `beai:sync-llm-registry` —
 * never by `db:seed` directly in production.
 *
 * @return list<array<string, mixed>>
 */
return [
    [
        'key' => 'gemini-3-flash-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3 Flash Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => LlmCapability::Text->value,
        'sort_order' => 10,
        'rate_card_source_url' => 'https://ai.google.dev/gemini-api/docs/pricing',
        'rate_card_verified_at' => '2026-08-26 00:00:00',
        'text_input_usd_per_million' => '0.500000',
        'text_output_usd_per_million' => '3.000000',
        'text_input_usd_per_million_high' => null,
        'text_output_usd_per_million_high' => null,
        'context_tier_threshold_tokens' => null,
        'audio_input_usd_per_million' => '1.000000',
        'audio_output_usd_per_million' => null,
        'audio_input_usd_per_minute' => null,
        'audio_output_usd_per_minute' => null,
        'audio_tokens_per_second' => null,
    ],
    [
        'key' => 'gemini-3.1-pro-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3.1 Pro Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => LlmCapability::Text->value,
        'sort_order' => 20,
        'rate_card_source_url' => 'https://ai.google.dev/gemini-api/docs/pricing',
        'rate_card_verified_at' => '2026-08-26 00:00:00',
        'text_input_usd_per_million' => '2.000000',
        'text_output_usd_per_million' => '12.000000',
        // Context-length pricing tier (design.md C-B) — the high rate above
        // 200k tokens, which the estimator selects PER REQUEST from that
        // request's own context size, never from the session total.
        'text_input_usd_per_million_high' => '4.000000',
        'text_output_usd_per_million_high' => '18.000000',
        'context_tier_threshold_tokens' => 200000,
        // Genuinely unpublished — NOT zero, NOT copied from the text rate.
        'audio_input_usd_per_million' => null,
        'audio_output_usd_per_million' => null,
        'audio_input_usd_per_minute' => null,
        'audio_output_usd_per_minute' => null,
        'audio_tokens_per_second' => null,
    ],
    [
        'key' => 'gemini-3.1-flash-live-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3.1 Flash Live Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => LlmCapability::NativeDuplex->value,
        'sort_order' => 30,
        'rate_card_source_url' => 'https://ai.google.dev/gemini-api/docs/pricing',
        'rate_card_verified_at' => '2026-08-26 00:00:00',
        'text_input_usd_per_million' => '0.750000',
        'text_output_usd_per_million' => '4.500000',
        'text_input_usd_per_million_high' => null,
        'text_output_usd_per_million_high' => null,
        'context_tier_threshold_tokens' => null,
        'audio_input_usd_per_million' => '3.000000',
        'audio_output_usd_per_million' => '12.000000',
        'audio_input_usd_per_minute' => '0.005000',
        'audio_output_usd_per_minute' => '0.018000',
        // NOT 25 — that rate is published for 3.5 Live Translate and Omni
        // Flash Preview, neither of which is this model (design.md C-C).
        'audio_tokens_per_second' => null,
    ],
    [
        'key' => 'gemini-2.5-flash-native-audio-preview-12-2025',
        'vendor' => 'google',
        'display_name' => 'Gemini 2.5 Flash Native Audio Preview (Dec 2025)',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => LlmCapability::NativeDuplex->value,
        'sort_order' => 40,
        'rate_card_source_url' => 'https://ai.google.dev/gemini-api/docs/pricing',
        'rate_card_verified_at' => '2026-08-26 00:00:00',
        'text_input_usd_per_million' => '0.500000',
        'text_output_usd_per_million' => '2.000000',
        'text_input_usd_per_million_high' => null,
        'text_output_usd_per_million_high' => null,
        'context_tier_threshold_tokens' => null,
        'audio_input_usd_per_million' => '3.000000',
        'audio_output_usd_per_million' => '12.000000',
        'audio_input_usd_per_minute' => null,
        'audio_output_usd_per_minute' => null,
        'audio_tokens_per_second' => null,
    ],
];
