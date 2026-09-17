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
        // fix/heygen-gemini-flash-lite: `gemini-3-flash-preview` above is a
        // THINKING model, and HeyGen's turn-based FULL-mode custom-LLM path
        // has no room for a thinking pass between a candidate's turn ending
        // and the avatar's next line — the session stalls after the opening
        // line (which this model never generates) on the model's own first
        // real turn. This row is the replacement: thinking OFF by default,
        // ~0.7s measured time-to-first-token against the real BEAI interview
        // prompt in a production spike, with no reasoning-token latency.
        //
        // `gemini-3.1-flash-lite-preview`, WITH the suffix — confirmed
        // 2026-09-17 by querying
        // `GET https://generativelanguage.googleapis.com/v1beta/openai/models`
        // directly from the production container: it returns 200 and lists
        // this id as currently available. `gemini-3.1-flash-lite` (no
        // `-preview`) is also listed there, but it was never measured
        // against the interview prompt — this row binds to the id that was
        // actually benchmarked, not the unmeasured sibling.
        //
        // Pricing: matches the published `gemini-3.1-flash-lite` rate below
        // because Google has not published a separate rate card entry for
        // the `-preview` id; UNVERIFIED that the two ids are billed
        // identically — flag for confirmation before this row drives real
        // spend at volume.
        'key' => 'gemini-3.1-flash-lite-preview',
        'vendor' => 'google',
        'display_name' => 'Gemini 3.1 Flash Lite Preview',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => LlmCapability::Text->value,
        'sort_order' => 15,
        'rate_card_source_url' => 'https://ai.google.dev/gemini-api/docs/pricing',
        'rate_card_verified_at' => '2026-09-17 00:00:00',
        'text_input_usd_per_million' => '0.250000',
        'text_output_usd_per_million' => '1.500000',
        'text_input_usd_per_million_high' => null,
        'text_output_usd_per_million_high' => null,
        'context_tier_threshold_tokens' => null,
        // Published separately from the text/image/video rate above.
        'audio_input_usd_per_million' => '0.500000',
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
