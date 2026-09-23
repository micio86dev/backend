<?php

declare(strict_types=1);

namespace App\Exceptions\Sso;

use RuntimeException;

/**
 * Thrown by `App\Actions\Scheduling\RescheduleParticipant` and
 * `App\Actions\Scheduling\CancelParticipantSchedule` when a reschedule/cancel
 * mutation is refused (interview-scheduling, design AD-7).
 *
 * Carries a closed-set `reason` — never an HTTP status. Both callers
 * (`ParticipantScheduleController`, `M2m\ParticipantController`) catch this
 * and rebuild their OWN literal response, exactly like `EntryLinkRefused`.
 *
 * REQ: Scheduled Start Can Be Rescheduled Before/After Its Notice Is Sent,
 *      Scheduled Start Can Be Cancelled Before It Fires,
 *      A Sent Start Email Makes The Schedule Terminal
 *      (sdd/interview-scheduling/spec, Engram #2221)
 */
final class ParticipantScheduleRefused extends RuntimeException
{
    public function __construct(
        public readonly ParticipantScheduleRefusalReason $reason,
        ?string $message = null,
    ) {
        parent::__construct($message ?? $reason->value);
    }
}
