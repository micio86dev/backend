<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Scheduling\CancelParticipantSchedule;
use App\Actions\Scheduling\RescheduleParticipant;
use App\Exceptions\Sso\ParticipantScheduleRefusalReason;
use App\Exceptions\Sso\ParticipantScheduleRefused;
use App\Http\Controllers\Controller;
use App\Http\Resources\ParticipantResource;
use App\Policies\ParticipantPolicy;
use App\Rules\ScheduledStartWithinLeadTime;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ParticipantScheduleController (interview-scheduling, design AD-7, tasks T-E3).
 *
 * Routes: PATCH/DELETE /api/participants/{id}/schedule
 * Auth: auth:api + TenantContext
 *
 * Modeled directly on `ParticipantRecoveryController`: thin by design —
 * authorize, validate, delegate to `RescheduleParticipant`/
 * `CancelParticipantSchedule`, the ONLY places the locked state transition
 * lives. This controller never resolves a participant row itself (the
 * `AdminTenancySafetyArchTest` bare-model-static-call guard forbids that
 * under app/Http/Controllers/Api) — every row read/lock/write happens
 * inside the injected actions.
 *
 * Failure order 403 -> 404 -> 409/422, matching `ParticipantRecoveryController`'s
 * own documented order: authorization runs BEFORE any row is resolved, so a
 * `viewer` never learns whether a given id even exists in their org. A
 * cross-org id then 404s (via the action's own org-scoped lookup) before any
 * scheduling-state refusal is possible.
 *
 * Own route group, adjacent to (NOT inside) the Admin Read API block and the
 * Participant Recovery block — it is a WRITE, not a read.
 *
 * REQ: Scheduled Start Can Be Rescheduled Before/After Its Notice Is Sent,
 *      Scheduled Start Can Be Cancelled Before It Fires,
 *      A Sent Start Email Makes The Schedule Terminal,
 *      Reschedule And Cancel Are Symmetric Across Both Surfaces
 *      (sdd/interview-scheduling/spec, Engram #2221)
 */
final class ParticipantScheduleController extends Controller
{
    public function __construct(
        private readonly RescheduleParticipant $reschedule,
        private readonly CancelParticipantSchedule $cancel,
        private readonly TenantResolver $resolver,
    ) {}

    /**
     * PATCH /api/participants/{id}/schedule
     */
    public function update(Request $request, int $id): JsonResponse
    {
        // (D4 order) 403 before 404 — model-less ability check, mirrors
        // ParticipantRecoveryController's authorize('recover', ...) call.
        $this->authorize('update', ParticipantPolicy::MODEL);

        $validated = $request->validate([
            'scheduled_at' => ['required', new ScheduledStartWithinLeadTime],
        ]);

        try {
            $updated = $this->reschedule->handle(
                $id,
                (int) $this->resolver->getOrgId(),
                Carbon::parse($validated['scheduled_at']),
            );
        } catch (ParticipantScheduleRefused $e) {
            return response()->json(['reason' => $e->reason->value], $this->statusFor($e->reason));
        }

        return response()->json(new ParticipantResource($updated), 200);
    }

    /**
     * DELETE /api/participants/{id}/schedule
     */
    public function destroy(int $id): JsonResponse
    {
        $this->authorize('update', ParticipantPolicy::MODEL);

        try {
            $updated = $this->cancel->handle($id, (int) $this->resolver->getOrgId());
        } catch (ParticipantScheduleRefused $e) {
            return response()->json(['reason' => $e->reason->value], $this->statusFor($e->reason));
        }

        return response()->json(new ParticipantResource($updated), 200);
    }

    /**
     * Maps a refusal reason onto this endpoint's OWN HTTP status — the
     * reason travels on the exception, the response literal never does
     * (mirrors `EntryLinkRefused`'s documented convention). Duplicated
     * (not shared) with `M2m\ParticipantController`'s own mapping,
     * deliberately: each surface owns its own response shape, and the two
     * mappings are required to agree only on VALUES (AD-7's symmetry
     * requirement), not on a shared implementation.
     */
    private function statusFor(ParticipantScheduleRefusalReason $reason): int
    {
        return match ($reason) {
            ParticipantScheduleRefusalReason::Terminal => 409,
            ParticipantScheduleRefusalReason::LeadTimeTooShort,
            ParticipantScheduleRefusalReason::NotScheduled => 422,
        };
    }
}
