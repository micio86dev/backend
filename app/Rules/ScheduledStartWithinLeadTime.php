<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Scheduling\ScheduledInterviewWindow;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * ScheduledStartWithinLeadTime (interview-scheduling, design AD-2/AD-3).
 *
 * Shared validation rule for the `scheduled_at` field, used identically by
 * every surface that accepts it: the backoffice entry-link create
 * (`EntryLinkController`), the M2M participant create, and the
 * reschedule endpoint — one rule object, never re-typed as inline logic
 * three times (AD-3's explicit "one rule is easier to test... between the
 * two surfaces").
 *
 * Enforces, in order:
 *   1. An explicit UTC offset or `Z` suffix (AD-2) — a bare local datetime
 *      with no offset is rejected outright, since "future" is only
 *      unambiguous once the offset is known.
 *   2. Strictly later than the server's own `now()` (AD-10 — never a
 *      client-supplied "current time").
 *   3. At least `ScheduledInterviewWindow::MINIMUM_SCHEDULING_LEAD_MINUTES`
 *      minutes ahead of `now()`.
 *
 * Each failure produces a DISTINCT message, so a caller can tell a plain
 * past-time rejection apart from a too-close-to-now rejection (spec:
 * "a reason distinguishing it from an ordinary past-time rejection").
 */
final class ScheduledStartWithinLeadTime implements ValidationRule
{
    /**
     * Anchored ISO-8601 pattern requiring an explicit `Z` or `+HH:MM`/`-HH:MM`
     * offset — the same "validate the shape, don't trust the caller"
     * discipline already used for `organizations.primary_color`'s `#rrggbb`
     * CHECK (ruling 9).
     */
    private const OFFSET_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::OFFSET_PATTERN, $value) !== 1) {
            $fail('The :attribute must be an ISO-8601 timestamp with an explicit UTC offset (e.g. 2026-10-01T14:00:00+02:00).');

            return;
        }

        try {
            $scheduledAt = Carbon::parse($value);
        } catch (Throwable) {
            $fail('The :attribute is not a valid date.');

            return;
        }

        $now = Carbon::now();

        if ($scheduledAt->lessThanOrEqualTo($now)) {
            $fail('The :attribute must be a time in the future.');

            return;
        }

        if ($now->diffInMinutes($scheduledAt) < ScheduledInterviewWindow::MINIMUM_SCHEDULING_LEAD_MINUTES) {
            $fail('The :attribute must be at least '.ScheduledInterviewWindow::MINIMUM_SCHEDULING_LEAD_MINUTES.' minutes from now.');
        }
    }
}
