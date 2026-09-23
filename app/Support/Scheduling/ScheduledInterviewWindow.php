<?php

declare(strict_types=1);

namespace App\Support\Scheduling;

/**
 * Named lead-time constants for the interview-scheduling feature (design AD-3).
 *
 * A single source of truth a validation rule derives from — never re-typed as
 * a magic number — mirroring the existing
 * `App\Support\Jwt\CandidateTokenFactory::SSO_LINK_TTL_MINUTES` pattern.
 *
 * `MINIMUM_SCHEDULING_LEAD_MINUTES` is deliberately one minute over
 * `NOTICE_LEAD_MINUTES`: the same 16-minute rule applies uniformly to initial
 * creation and to every reschedule (whether still pending or already past its
 * notice), leaving a full one-minute margin over the notice window against
 * the sweep's own one-minute polling granularity (design AD-4), which a bare
 * 15 would not.
 *
 * Not per-tenant-configurable — no product signal calls for it, and this
 * class can become additive/config-driven later without touching the spec,
 * which never named a literal value.
 */
final class ScheduledInterviewWindow
{
    /**
     * Minutes before `scheduled_at` at which the notice email fires.
     */
    public const int NOTICE_LEAD_MINUTES = 15;

    /**
     * Minimum minutes of lead time enforced at creation and at every
     * reschedule (before or after the notice has already fired).
     */
    public const int MINIMUM_SCHEDULING_LEAD_MINUTES = 16;

    /**
     * How long `DispatchScheduledInterviewInvitations` keeps retrying a
     * participant whose processing threw (a save deadlock, a lock-wait
     * timeout — the genuinely transient case) before giving up on it.
     *
     * The sweep cannot always tell a transient failure from a permanent one
     * from inside a generic `catch (Throwable)`, so once a row has been due
     * for longer than this many minutes without ever converging, it is
     * cancelled outright rather than re-selected and re-attempted forever —
     * a pragmatic bound, not a precise transient/permanent classifier.
     */
    public const int MAX_RETRY_STALENESS_MINUTES = 60;
}
