<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Scheduling milestone state of a `participants` row (interview-scheduling,
 * design AD-1).
 *
 * Paired 1:1 with `participants.scheduled_at`: both columns are null together
 * (never scheduled — today's immediate path) or both are non-null (a
 * candidate created via the scheduled path). This enum itself has no case for
 * "never scheduled" — the pair of nullable columns carries that meaning, not
 * a fifth case, per the DB CHECK constraint added in the same migration
 * (`(scheduled_at IS NULL) = (scheduling_status IS NULL)`).
 *
 * Transitions (design AD-5, enforced by the sweep and the reschedule/cancel
 * endpoints — not by this enum):
 *   (eager create) → Pending
 *   Pending        → NoticeSent | Cancelled
 *   NoticeSent     → Started | Cancelled | Pending (re-armed by a reschedule)
 *   Started        → [] (terminal)
 *   Cancelled      → [] (terminal)
 *
 * Machine values; never localized.
 */
enum ParticipantSchedulingStatus: string
{
    case Pending = 'pending';
    case NoticeSent = 'notice_sent';
    case Started = 'started';
    case Cancelled = 'cancelled';
}
