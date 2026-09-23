<?php

declare(strict_types=1);

namespace App\Actions\Scheduling;

use App\Enums\ParticipantSchedulingStatus;
use App\Exceptions\Sso\ParticipantScheduleRefusalReason;
use App\Exceptions\Sso\ParticipantScheduleRefused;
use App\Models\Participant;
use App\Support\Scheduling\ScheduledInterviewWindow;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RescheduleParticipant (interview-scheduling, design AD-5/AD-7, tasks T-E2).
 *
 * Lives under app/Actions (not app/Http/Controllers/Api), mirroring
 * `RecoverFailedParticipant`'s own placement exactly: this is a WRITE with
 * its own row lock, and the `AdminTenancySafetyArchTest` bare-`Participant::`
 * guard applies only to app/Http/Controllers/Api — no controller in that
 * directory may resolve a participant directly, only through an action like
 * this one.
 *
 * Idempotency/locking discipline (AD-5): `lockForUpdate()` inside
 * `DB::transaction()`, org-filtered in the SAME query as the lock (cross-org
 * id -> ModelNotFoundException -> 404, before any status is even read) —
 * identical shape to `RecoverFailedParticipant::handle()`. This is also what
 * makes a reschedule racing an in-flight sweep tick (design AD-10) resolve
 * correctly: whichever transaction commits first wins, and the other's own
 * re-read under its own lock sees the already-resolved state.
 *
 * Transition table (spec, Engram #2221):
 *   Pending    -> scheduled_at updated, stays Pending
 *   NoticeSent -> scheduled_at updated, re-arms to Pending (a fresh notice
 *                 fires 15 minutes before the new time)
 *   Started    -> refused, Terminal (409)
 *   null | Cancelled -> refused, NotScheduled (422) — a cancelled schedule
 *                 is never silently resurrected by a reschedule; there is no
 *                 spec requirement authorizing reactivation
 *
 * The minimum-lead-time re-check below is DELIBERATE defense in depth: the
 * SAME `App\Rules\ScheduledStartWithinLeadTime` rule already enforces this at
 * the HTTP request-validation layer (AD-3), but that check runs BEFORE this
 * action acquires the row lock — re-checking under the lock closes the gap
 * between validation and the lock exactly as AD-5's "lock, re-check, only
 * then act" discipline requires everywhere else in this feature.
 */
final class RescheduleParticipant
{
    /**
     * @throws ModelNotFoundException When the participant does not belong to
     *                                the caller's organization (-> 404).
     * @throws ParticipantScheduleRefused When the schedule is terminal, has
     *                                    no active schedule, or the new time
     *                                    no longer clears the minimum lead
     *                                    time (-> 409 / 422).
     */
    public function handle(int $participantId, int $organizationId, Carbon $newScheduledAt): Participant
    {
        return DB::transaction(function () use ($participantId, $organizationId, $newScheduledAt): Participant {
            $participant = Participant::where('organization_id', $organizationId)
                ->lockForUpdate()
                ->findOrFail($participantId);

            $status = $participant->scheduling_status;

            if ($status === null || $status === ParticipantSchedulingStatus::Cancelled) {
                throw new ParticipantScheduleRefused(ParticipantScheduleRefusalReason::NotScheduled);
            }

            if ($status === ParticipantSchedulingStatus::Started) {
                throw new ParticipantScheduleRefused(ParticipantScheduleRefusalReason::Terminal);
            }

            $now = Carbon::now();

            if ($newScheduledAt->lessThanOrEqualTo($now)
                || $now->diffInMinutes($newScheduledAt) < ScheduledInterviewWindow::MINIMUM_SCHEDULING_LEAD_MINUTES) {
                throw new ParticipantScheduleRefused(ParticipantScheduleRefusalReason::LeadTimeTooShort);
            }

            // Pending stays Pending; NoticeSent re-arms to Pending — the
            // candidate already holds a notice naming the OLD time, so the
            // sweep must send a FRESH one for the new time (spec: "re-arms a
            // fresh notice").
            $participant->scheduled_at = $newScheduledAt;
            $participant->scheduling_status = ParticipantSchedulingStatus::Pending;
            $participant->save();

            return $participant;
        });
    }
}
