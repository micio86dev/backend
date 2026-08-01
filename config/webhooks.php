<?php

declare(strict_types=1);

/**
 * Webhooks Integration configuration (C10 — Webhooks Integration).
 *
 * Keys:
 *   payload.version           — schema version stamped into every outbound payload body
 *                               (design.md D7/D9); bump on ANY breaking payload shape change.
 *   signature.algo            — HMAC algorithm; sha256 per design.md D6.
 *   signature.version_prefix  — the "v1=" prefix on the X-BEAI-Signature header.
 *   signature.replay_window_seconds — published to receivers as guidance; BEAI is the
 *                               sender and does not enforce it (design.md D6).
 *   delivery.queue            — queue name; null (default queue) — S13: no dedicated
 *                               "webhooks" queue exists because no worker consumes it yet.
 *   delivery.max_attempts     — total delivery attempts before dead-lettering.
 *   delivery.backoff_seconds  — retry delay curve; count MUST equal max_attempts - 1
 *                               (design.md D3 config-invariant test).
 *   http.timeout_seconds      — per-attempt HTTP read timeout so a hung receiver cannot
 *                               occupy a worker.
 *   http.connect_timeout_seconds — per-attempt HTTP connect timeout.
 *   http.user_agent           — User-Agent header sent on every delivery POST.
 *   errors.max_last_error_chars — truncation length for webhook_deliveries.last_error,
 *                               applied AFTER secret redaction (design.md D6), never before.
 *   events.types              — the closed, enumerable event-type set. NOT
 *                               env-overridable — this is a schema-level invariant, not
 *                               deployment config.
 *
 * REQ: config/webhooks.php (C10 D8)
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Payload
    |--------------------------------------------------------------------------
    */
    'payload' => [
        'version' => env('WEBHOOK_PAYLOAD_VERSION', '1.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signature
    |--------------------------------------------------------------------------
    |
    | X-BEAI-Signature: v1={hex}, computed as hash_hmac('sha256', "{ts}.{raw_body}", secret).
    |
    */
    'signature' => [
        'algo' => 'sha256',
        'version_prefix' => 'v1',
        'replay_window_seconds' => (int) env('WEBHOOK_REPLAY_WINDOW', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | backoff_seconds has one entry per RETRY, not per attempt: with max_attempts=6
    | there are 5 retry gaps between the 6 attempts. count(backoff_seconds) MUST equal
    | max_attempts - 1 — guarded by a config-invariant unit test.
    |
    */
    'delivery' => [
        'queue' => env('WEBHOOK_QUEUE'),
        'max_attempts' => (int) env('WEBHOOK_MAX_ATTEMPTS', 6),
        'backoff_seconds' => [10, 60, 300, 1800, 7200],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */
    'http' => [
        'timeout_seconds' => (int) env('WEBHOOK_TIMEOUT', 10),
        'connect_timeout_seconds' => (int) env('WEBHOOK_CONNECT_TIMEOUT', 5),
        'user_agent' => env('WEBHOOK_USER_AGENT', 'BEAI-Webhooks/1.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Errors
    |--------------------------------------------------------------------------
    */
    'errors' => [
        'max_last_error_chars' => (int) env('WEBHOOK_MAX_ERROR_CHARS', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | Closed, enumerable set — NOT env-overridable. A schema-level invariant, not
    | deployment config; changing it requires a code change plus a migration for
    | projects.webhook_events validation to keep matching.
    |
    */
    'events' => [
        'types' => ['progress', 'evaluation'],
    ],

];
