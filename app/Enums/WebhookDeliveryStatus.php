<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Webhook delivery state machine status (C10 — Webhooks Integration, D2).
 *
 * Transitions:
 *   (insert) → skipped | pending
 *   pending  → delivered | pending (retry) | failed_permanent | dead
 *
 * 'delivered', 'failed_permanent', 'dead', and 'skipped' are terminal. The job's first
 * action is a guard that returns immediately if the row is not 'pending' (idempotency
 * under queue re-delivery). There is deliberately no 'delivering' in-flight state — on
 * the `database` driver a worker crash would strand rows there forever with no reaper.
 *
 * REQ: WebhookDeliveryStatus enum (C10 D2)
 */
enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case FailedPermanent = 'failed_permanent';
    case Dead = 'dead';
    case Skipped = 'skipped';
}
