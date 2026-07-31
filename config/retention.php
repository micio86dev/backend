<?php

declare(strict_types=1);

/**
 * GDPR retention policy (C13).
 *
 * ── READ THIS BEFORE SETTING A NUMBER ───────────────────────────────────────
 *
 * `enabled` is FALSE by default and must stay false until open product
 * decision #2 has legal sign-off. A purge that runs before its durations are
 * ratified deletes data nobody agreed to delete, and deletion is the one
 * operation with no undo.
 *
 * The sign-off must additionally cover `webhook_payload` and `participant_pii`:
 * both carry candidate data and both postdate the original framing of that
 * decision, so a sign-off written against the old scope does not authorise them.
 *
 * Every duration below is NULL, deliberately. Null is not "keep forever" and
 * not "delete now" — it is an unratified decision, and the purge skips that
 * class loudly rather than guessing in either direction.
 *
 * The mechanism is complete and tested against fixture durations. Ratification
 * is a config change, never a code change.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | False until decision #2 is ratified. The command is a reported no-op while
    | this is false — not a silent one: an operator who runs it deserves to be
    | told why nothing happened.
    |
    */
    'enabled' => (bool) env('RETENTION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Retention windows, in days, per artifact class
    |--------------------------------------------------------------------------
    |
    | snapshot         — interview_snapshots rows AND the stored objects they
    |                    point at. Deleting the row alone would leave the image
    |                    on disk, unreachable and fully present.
    | transcript       — utterances rows.
    | webhook_payload  — webhook_deliveries.payload is NULLED; the delivery row
    |                    survives. Whether a customer's endpoint was told, and
    |                    when, is an integration audit record that must outlive
    |                    the purge of what was said.
    | participant_pii  — participants.display_name is NULLED; candidate_ref
    |                    survives. That reference is the calling system's own
    |                    opaque identifier and carries no personal data, so
    |                    removing the row would destroy the audit trail without
    |                    protecting anybody.
    |
    */
    'days' => [
        'snapshot' => env('RETENTION_SNAPSHOT_DAYS') !== null ? (int) env('RETENTION_SNAPSHOT_DAYS') : null,
        'transcript' => env('RETENTION_TRANSCRIPT_DAYS') !== null ? (int) env('RETENTION_TRANSCRIPT_DAYS') : null,
        'webhook_payload' => env('RETENTION_WEBHOOK_PAYLOAD_DAYS') !== null ? (int) env('RETENTION_WEBHOOK_PAYLOAD_DAYS') : null,
        'participant_pii' => env('RETENTION_PARTICIPANT_PII_DAYS') !== null ? (int) env('RETENTION_PARTICIPANT_PII_DAYS') : null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch size
    |--------------------------------------------------------------------------
    |
    | Bounded so a first run against years of backlog cannot hold a transaction
    | open long enough to matter. The command is idempotent, so a partial run
    | simply resumes on the next tick.
    |
    */
    'batch_size' => (int) env('RETENTION_BATCH_SIZE', 500),

];
