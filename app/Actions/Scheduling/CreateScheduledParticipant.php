<?php

declare(strict_types=1);

namespace App\Actions\Scheduling;

use App\Enums\ParticipantSchedulingStatus;
use App\Models\Participant;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CreateScheduledParticipant (interview-scheduling, design AD-1 amendment,
 * tasks T-B1).
 *
 * Eagerly creates a `participants` row for the SCHEDULED path — the one
 * shared code path both `EntryLinkController::store()` (backoffice) and
 * `M2m\ParticipantController::store()` (M2M) call for a create request that
 * carries `scheduled_at`, per AD-1's "one shared code path for the
 * scheduling decision, not two divergent implementations."
 *
 * Mirrors `M2m\ParticipantController::store()`'s own row-creation shape
 * exactly (`forceFill()` + `DB::transaction()` + unique-constraint → 409
 * mapping): `organization_id` is ALWAYS taken from the resolved `Project`,
 * never from caller input (named security invariant, `Participant.php`).
 * `status` is hardcoded to `in_attesa` — the candidate lifecycle is a
 * binding domain constraint and this action creates the FIRST row, exactly
 * like the M2M controller already does for its own immediate create.
 *
 * Does NOT fire `App\Events\ParticipantCreated` — consistent with
 * `M2m\ParticipantController::store()`'s own existing precedent (AD-11): a
 * caller that receives a synchronous 201 for a row it just created directly
 * has no need for the async progress webhook that event drives.
 */
final class CreateScheduledParticipant
{
    /**
     * @return array{participant: Participant, conflict: null}|array{participant: null, conflict: 'duplicate_candidate_ref'|'duplicate_email'}
     */
    public function handle(
        Project $project,
        string $candidateRef,
        string $displayName,
        string $email,
        ?string $roleCode,
        ?string $language,
        Carbon $scheduledAt,
    ): array {
        $participant = new Participant;
        $participant->forceFill([
            'organization_id' => $project->organization_id, // server-side, NOT from request
            'project_id' => $project->id,
            'candidate_ref' => $candidateRef,
            'display_name' => $displayName,
            'email' => $email,
            'role_code' => $roleCode,
            'language' => $language,
            'status' => 'in_attesa',
            'scheduled_at' => $scheduledAt,
            'scheduling_status' => ParticipantSchedulingStatus::Pending,
        ]);

        // DB::transaction() — not a bare save() — for the SAME reason
        // M2m\ParticipantController::store() uses it: Postgres aborts the
        // WHOLE ambient transaction on a failed statement, not just the one
        // that failed. This opens a SAVEPOINT so only this insert unwinds.
        try {
            DB::transaction(function () use ($participant): void {
                $participant->save();
            });
        } catch (QueryException $e) {
            $reason = match (true) {
                str_contains($e->getMessage(), 'participants_project_id_candidate_ref_unique') => 'duplicate_candidate_ref',
                str_contains($e->getMessage(), 'participants_project_id_email_unique') => 'duplicate_email',
                default => null,
            };

            if ($reason === null) {
                throw $e;
            }

            return ['participant' => null, 'conflict' => $reason];
        }

        return ['participant' => $participant, 'conflict' => null];
    }
}
