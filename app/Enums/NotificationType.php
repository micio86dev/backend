<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Operator notification types (C12 — Notifications & Reminders, D5).
 *
 * Exactly two, and the shortness of this list is the design, not an omission.
 * Both are RARE and both require a human to act:
 *
 *   - WebhookDeliveryDead — six attempts over ~2.7h have failed; the customer's
 *     system never received the evaluation and someone must fix an endpoint.
 *   - ScoringFailed — a candidate sat the interview and has no result.
 *
 * Deliberately absent, each for a stated reason (D5):
 *   - EvaluationCompleted → one per candidate; a 500-candidate campaign would be
 *     500 emails. Dashboard only (C11), and asserted absent by test.
 *   - Terminal `pending` (sub-90%) evaluations → a QUALITY signal, meaningful
 *     only in aggregate.
 *   - `failed_permanent` (4xx) → a misconfigured URL fires once per participant,
 *     so it is a storm by construction and a setup error, not an incident.
 *
 * These are MACHINE values. They are persisted in notification_logs, used as
 * suppression keys, and never localized (CLAUDE.md).
 */
enum NotificationType: string
{
    case WebhookDeliveryDead = 'webhook_delivery_dead';
    case ScoringFailed = 'scoring_failed';
}
