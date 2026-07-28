<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Reason a `webhook_deliveries` row was created with status='skipped' (C10, D2/D6/D10).
 *
 * Exactly 3 cases after the Δ1 spec/design reconciliation (see
 * openspec/changes/webhooks-integration/tasks.md "Spec ↔ Design Reconciliation"):
 * the original 3-step delivery gate (null url → event type disabled → pending) grew a
 * 4th branch — url set but secret null — inserted between the null-url check and the
 * event-type check, closing a gap where an "unsigned" webhook would silently violate
 * the binding "verificabile dal ricevente" requirement.
 *
 * REQ: WebhookSkipReason enum (C10 D2, D6 Δ1)
 */
enum WebhookSkipReason: string
{
    case NoWebhookUrl = 'no_webhook_url';
    case NoWebhookSecret = 'no_webhook_secret';
    case EventTypeDisabled = 'event_type_disabled';
}
