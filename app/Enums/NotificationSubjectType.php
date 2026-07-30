<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a notification is ABOUT (C12 — Notifications & Reminders, D3).
 *
 * A string discriminator with NO foreign key, and that is deliberate. The
 * obvious alternative — two nullable typed FK columns plus a CHECK — puts NULLs
 * in the dedupe unique index, and Postgres treats NULLs as distinct, which
 * silently disables the arbiter. That is the exact defect class already fixed
 * once for webhook_deliveries.
 *
 * Orphan tolerance is a feature: an audit trail should survive its subject.
 *
 * Machine values; never localized.
 */
enum NotificationSubjectType: string
{
    case Participant = 'participant';
    case WebhookDelivery = 'webhook_delivery';
}
