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
    'snapshot' => [
        'max_encoded_bytes' => (int) env('SNAPSHOT_MAX_ENCODED_BYTES', 2_764_800),
    ],

];
