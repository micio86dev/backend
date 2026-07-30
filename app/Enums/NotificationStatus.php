<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a notification_logs row (C12 — Notifications & Reminders, D3/D8).
 *
 * Transitions:
 *   (insert) → pending
 *   pending  → sent | suppressed | failed
 *   failed   → sent           (a queue retry that finally succeeds)
 *
 * `sent` and `suppressed` are TERMINAL and are what makes the row an
 * idempotency arbiter: on a duplicate INSERT the existing row's status decides.
 * Terminal → no-op. `pending` / `failed` → an unfinished send, so continue.
 *
 * `failed` is deliberately NOT terminal and deliberately NOT an alert of its
 * own. Notifying someone that a notification failed is self-referential, needs
 * the same broken channel, and turns one outage into an amplification storm.
 * The row IS the signal — C11's org-scoped dashboard renders it as "we tried to
 * alert you and could not".
 *
 * Machine values; never localized.
 */
enum NotificationStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Suppressed = 'suppressed';
    case Failed = 'failed';
}
