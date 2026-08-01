<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Webhook event type (C10 — Webhooks Integration).
 *
 * The closed, enumerable set of event types a project may deliver. Mirrors
 * `config('webhooks.events.types')` — not env-overridable (design.md D8).
 *
 * REQ: WebhookEventType enum (C10 D1)
 */
enum WebhookEventType: string
{
    case Progress = 'progress';
    case Evaluation = 'evaluation';
}
