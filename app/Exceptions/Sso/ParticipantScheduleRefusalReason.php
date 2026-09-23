<?php

declare(strict_types=1);

namespace App\Exceptions\Sso;

/**
 * The closed set of reasons a reschedule/cancel mutation on a participant's
 * scheduled interview can be refused (interview-scheduling, design AD-7).
 * Mirrors `EntryLinkRefusalReason`'s shape exactly: each caller
 * (`ParticipantScheduleController`, `M2m\ParticipantController`) maps the
 * reason onto its OWN HTTP response — the reason travels, the response
 * literal never does.
 *
 * - Terminal: the schedule's start email has already been sent
 *   (`scheduling_status = Started`) — a sent start email makes the schedule
 *   terminal (spec requirement); neither reschedule nor cancel is ever
 *   allowed past that point. -> HTTP 409.
 * - LeadTimeTooShort: the new `scheduled_at` does not clear
 *   `ScheduledInterviewWindow::MINIMUM_SCHEDULING_LEAD_MINUTES` from now —
 *   re-checked here, under the row lock, as defense in depth even though the
 *   SAME `ScheduledStartWithinLeadTime` rule already enforces this at the
 *   HTTP request-validation layer (AD-3's "one rule, never re-typed").
 *   Distinguishable from the create path's plain "value is in the past"
 *   422 (spec: "a reason distinguishing it from an ordinary past-time
 *   rejection"). -> HTTP 422.
 * - NotScheduled: there is no active schedule to act on — either the
 *   participant was never scheduled (`scheduling_status` is `null`), or its
 *   schedule was already cancelled. A cancelled schedule refuses a further
 *   PATCH rather than silently resurrecting it (no spec requirement
 *   authorizes reactivating a cancelled schedule); a repeated DELETE on an
 *   already-cancelled schedule is instead treated as an idempotent no-op by
 *   the action, never reaching this reason (mirrors
 *   `RecoverFailedParticipant`'s own "already recovered" idempotency
 *   precedent). -> HTTP 422.
 */
enum ParticipantScheduleRefusalReason: string
{
    case Terminal = 'terminal';
    case LeadTimeTooShort = 'lead_time_too_short';
    case NotScheduled = 'not_scheduled';
}
