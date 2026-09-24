<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Webhook event type (C10 — Webhooks Integration).
 *
 * The closed, enumerable set of event types a project may deliver. Mirrors
 * `config('webhooks.events.types')` — not env-overridable (design.md D8).
 *
 * `HasValues` (public-api step 7): `App\Http\Controllers\PublicApi\
 * WebhookDeliveryController::validateFilters()` needs `values()` for its
 * `?event_type=` filter's `Rule::in()`, the same convention every other
 * `/v1` filterable enum already uses (`ProjectStatus`, `RoleCode`,
 * `AssessmentType`) — an additive, behaviour-free trait method, not a
 * change to this enum's cases or the C10 delivery pipeline that reads them.
 *
 * REQ: WebhookEventType enum (C10 D1)
 */
enum WebhookEventType: string
{
    use HasValues;

    case Progress = 'progress';
    case Evaluation = 'evaluation';
}
