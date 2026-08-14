<?php

declare(strict_types=1);

/**
 * Interview session configuration (C7a — Interview Session Mechanics).
 *
 * Keys:
 *   provider          — Default provider: 'heygen' | 'tavus'.
 *                       Overridable per-project via projects.provider_override.
 *   heygen.api_key    — HeyGen LiveAvatar API key (server-side only; NEVER returned to client).
 *   tavus.api_key     — Tavus API key (server-side only; NEVER returned to client).
 *   snapshot.max_encoded_bytes — Maximum allowed base64-ENCODED snapshot size (~2.7 MB).
 *                       At this limit the decoded payload is ~2 MB (base64 overhead ~33%).
 *                       Checked BEFORE decoding (reject early to avoid OOM on huge inputs).
 *
 * Security:
 * - Provider API keys are read from env and NEVER serialized into responses, logs, or jobs.
 * - HeygenProvider and TavusProvider MUST redact raw response bodies before re-throwing exceptions.
 *
 * Open question (C7a): HeyGen LiveAvatar vs native HeyGen REST endpoint to confirm.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Default Interview Provider
    |--------------------------------------------------------------------------
    |
    | The provider used when a project does not set provider_override.
    | Valid values: 'heygen' | 'tavus'
    |
    */
    'provider' => env('INTERVIEW_PROVIDER', 'heygen'),

    /*
    |--------------------------------------------------------------------------
    | HeyGen Provider Configuration
    |--------------------------------------------------------------------------
    */
    'heygen' => [
        'api_key' => env('HEYGEN_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tavus Provider Configuration
    |--------------------------------------------------------------------------
    */
    'tavus' => [
        'api_key' => env('TAVUS_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Snapshot Upload Limits
    |--------------------------------------------------------------------------
    |
    | max_encoded_bytes: Maximum base64-ENCODED string length to accept before
    | decoding. Checked FIRST (before decode) to prevent OOM on oversized inputs.
    |
    | ~2 764 800 bytes encoded ≈ ~2 MB decoded (base64 overhead is ~33%).
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Provider Cost Rates (C11 review surface)
    |--------------------------------------------------------------------------
    |
    | Used to ESTIMATE what a session cost. Neither provider exposes a
    | per-session billed amount through an API, so the figure is always an
    | estimate and must be labelled as one wherever it surfaces — an operator
    | who reads it as an invoice line will reconcile it against a real bill and
    | find a discrepancy that was never a defect.
    |
    | Defaults mirror `quint-avatar-tester/src/lib/pricing.ts`, verified against
    | THAT project's plan. BEAI's contracts may differ; these are env-overridable
    | precisely so a wrong rate is a config change, not a release.
    |
    | HeyGen bills credits per minute at a per-credit rate; Tavus bills per
    | conversational minute.
    |
    */
    'rates' => [
        'heygen' => [
            'credits_per_minute' => (float) env('HEYGEN_CREDITS_PER_MIN', 2),
            'usd_per_credit' => (float) env('HEYGEN_USD_PER_CREDIT', 0.10),
        ],
        'tavus' => [
            'usd_per_minute' => (float) env('TAVUS_USD_PER_MIN', 0.37),
        ],
    ],

    'snapshot' => [
        'max_encoded_bytes' => (int) env('SNAPSHOT_MAX_ENCODED_BYTES', 2_764_800),
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo Avatar Identity (beai:demo-seed)
    |--------------------------------------------------------------------------
    |
    | Resource identifiers `beai:demo-seed` writes into its demo
    | AvatarTemplate rows. LIVEAVATAR_* is accepted as an alias of HEYGEN_*:
    | LiveAvatar is HeyGen's product name for the same service. Falls back to
    | committed, WORKING values (never a `demo_*` placeholder) so a fresh
    | checkout with no env vars set still seeds a demo that connects.
    |
    */
    'demo' => [
        'heygen' => [
            'avatar_id' => env('HEYGEN_AVATAR_ID', env('LIVEAVATAR_AVATAR_ID', 'ab0765ad-69de-41fb-9f8a-bd01c3c52d6f')),
            'voice_id' => env('HEYGEN_VOICE_ID', env('LIVEAVATAR_VOICE_ID', 'c84af063-5ce2-4370-8ef8-dcd0ef903d43')),
            'language' => env('HEYGEN_LANGUAGE', env('LIVEAVATAR_LANGUAGE', 'it')),
        ],
        'tavus' => [
            'replica_id' => env('TAVUS_REPLICA_ID', 'rf4e9d9790f0'),
            'persona_id' => env('TAVUS_PERSONA_ID', 'p8a490c4dfd4'),
        ],
    ],

];
