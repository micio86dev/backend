<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a notification was recorded but not sent (C12 — D2/D4).
 *
 *   - Window        — a notification of this (org, type) was already sent inside
 *                     the suppression window. The row is still written, and the
 *                     NEXT send carries `suppressed_carried_count`, so silence
 *                     is never total: the operator sees "1 new failure — 46
 *                     further failures suppressed in the last 15 minutes".
 *   - NoRecipients  — the org has no user holding a recipient role. This is a
 *                     recorded OUTCOME, not a crash: an alerting job that throws
 *                     because nobody is listening helps nobody, and the row is
 *                     what makes the gap visible in the dashboard.
 *   - DemoProject   — the subject belongs to a project written by
 *                     `beai:demo-seed` (DemoMarker prefix on `projects.slug`).
 *                     Demo webhooks target an RFC 2606 reserved domain that
 *                     cannot resolve, so they dead-letter by design; alerting on
 *                     them teaches operators that the channel means nothing.
 *                     Ranked ABOVE Window and NoRecipients: it is the root fact,
 *                     and reporting either of the others would describe a
 *                     problem the organization does not have.
 *
 * NULL on any row whose status is not `suppressed`. Machine values; never
 * localized.
 */
enum NotificationSuppressionReason: string
{
    case Window = 'window';
    case NoRecipients = 'no_recipients';
    case DemoProject = 'demo_project';
}
