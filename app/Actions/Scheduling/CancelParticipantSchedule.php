<?php

declare(strict_types=1);

namespace App\Actions\Scheduling;

use App\Enums\ParticipantSchedulingStatus;
use App\Exceptions\Sso\ParticipantScheduleRefusalReason;
use App\Exceptions\Sso\ParticipantScheduleRefused;
use App\Models\Participant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * CancelParticipantSchedule (interview-scheduling, design AD-5/AD-7, tasks T-E2).
 *
 * Same placement/locking rationale as `RescheduleParticipant` — see its
 * docblock. Cancel is deliberately SILENT: no notification is ever sent for
 * a cancellation (spec's explicit requirement), so there is no notification
 * class to dispatch here, unlike the sweep's notice/start branches.
 *
 * Transition table (spec, Engram #2221):
 *   Pending | NoticeSent -> Cancelled, no email
 *   Started              -> refused, Terminal (409)
 *   Cancelled             -> idempotent no-op (200, unchanged) — repeating an
 *                            already-silent, side-effect-free cancel changes
 *                            nothing observable; mirrors
 *                            `RecoverFailedParticipant`'s own "already
 *                            recovered" idempotency precedent
 *   null (never scheduled) -> refused, NotScheduled (422)
 */
final class CancelParticipantSchedule
{
    /**
     * @throws ModelNotFoundException When the participant does not belong to
     *                                the caller's organization (-> 404).
     * @throws ParticipantScheduleRefused When the schedule is terminal or
     *                                    there is no active schedule to
     *                                    cancel (-> 409 / 422).
     */
    public function handle(int $participantId, int $organizationId): Participant
    {
        return DB::transaction(function () use ($participantId, $organizationId): Participant {
            $participant = Participant::where('organization_id', $organizationId)
                ->lockForUpdate()
                ->findOrFail($participantId);

            $status = $participant->scheduling_status;

            if ($status === null) {
                throw new ParticipantScheduleRefused(ParticipantScheduleRefusalReason::NotScheduled);
            }

            if ($status === ParticipantSchedulingStatus::Started) {
                throw new ParticipantScheduleRefused(ParticipantScheduleRefusalReason::Terminal);
            }

            if ($status === ParticipantSchedulingStatus::Cancelled) {
                return $participant;
            }

            $participant->scheduling_status = ParticipantSchedulingStatus::Cancelled;
            $participant->save();

            return $participant;
        });
    }
}
