<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

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
 * Public API step 7 adds ONE authorized edge back INTO 'pending': `App\Http\
 * Controllers\PublicApi\WebhookDeliveryController::redeliver()` reopens a
 * terminal 'delivered'/'failed_permanent'/'dead' row (G-15) — the C10
 * pipeline itself (`DeliverWebhookJob`) is otherwise unchanged.
 *
 * `HasValues` (public-api step 7): `WebhookDeliveryController::
 * validateFilters()` needs `values()` for its `?status=` filter's
 * `Rule::in()`, the same convention every other `/v1` filterable enum
 * already uses — an additive, behaviour-free trait method.
 *
 * REQ: WebhookDeliveryStatus enum (C10 D2)
 */
enum WebhookDeliveryStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Delivered = 'delivered';
    case FailedPermanent = 'failed_permanent';
    case Dead = 'dead';
    case Skipped = 'skipped';
}
