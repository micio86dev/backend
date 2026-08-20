<?php

declare(strict_types=1);

namespace App\Actions\Participant;

use App\Actions\InterviewSession\ResetSessionForRetry;
use App\Enums\WebhookEventType;
use App\Exceptions\Participant\RecoveryRefusalReason;
use App\Exceptions\Participant\RecoveryRefused;
use App\Models\InterviewSession;
use App\Models\Participant;
use App\Models\WebhookDelivery;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RecoverFailedParticipant (participant-error-recovery, design D1/D2/D5/D6).
 *
 * The SOLE authorized path back out of `errore`. This is a RESUME, not a
 * restart: atomically resets the participant (errore -> in_attesa) AND every
 * `InterviewSession` at status=error for that participant (-> pending,
 * cleared, utterances deleted) inside one `DB::transaction()` + row lock.
 * Sessions at completed/timeout/skipped are NEVER touched — the existing
 * `InterviewController::resolveNextCompetency()` skip logic still treats
 * them as terminal-completed, so the candidate resumes at the failed
 * competency, never re-asked anything already answered.
 *
 * Lives under app/Actions (not app/Http/Controllers/Api) so the
 * `AdminTenancySafetyArchTest` bare-`Participant::` guard does not fire here —
 * it applies the SAME org-filter shape `AdminParticipantReader::read()` uses
 * (D1), but cannot reuse that reader: this is a WRITE with its own row lock
 * and its own 403-before-404 failure order (D4), not a read.
 *
 * THE CRUX (design D2): a status-only flip is NOT enough and is actively
 * worse than today's lockout — `resolveNextCompetency()` treats `error` as
 * terminal-completed (silently skipping the failed competency forever) and
 * `/end`'s completion CAS can then never reach `totalCompetencies`, stranding
 * the candidate in `in_corso` permanently with scoring never dispatched. The
 * session reset below is what makes the failed competency reachable again.
 *
 * REQ: Atomic Participant and Session Recovery from Errore,
 *      Recovery Refusal Guards,
 *      Interim Recovery Audit Logging
 *      (openspec/changes/participant-error-recovery/specs/participant-sso/spec.md)
 */
final class RecoverFailedParticipant
{
    public function __construct(
        private readonly TenantResolver $resolver,
    ) {}

    /**
     * @throws ModelNotFoundException When the participant does not belong to
     *                                the caller's organization (-> 404, D4).
     * @throws RecoveryRefused When a guard refuses the recovery, or the
     *                         participant is a live (non-recoverable) status
     *                         (-> 409, D5/D6).
     */
    public function handle(int $participantId, ?int $actorUserId, ?string $reason): RecoveryResult
    {
        return DB::transaction(function () use ($participantId, $actorUserId, $reason): RecoveryResult {
            // Org filter (D1 shape) + row lock (D6) in one query. A cross-org
            // id 404s here, before any status is even read.
            $participant = Participant::where('organization_id', $this->resolver->getOrgId())
                ->lockForUpdate()
                ->findOrFail($participantId);

            // (D6) Status re-read INSIDE the lock. Idempotent no-op: already
            // recovered by a prior (or racing) call — the session reset and
            // utterance delete do NOT re-run.
            if ($participant->status === 'in_attesa') {
                return new RecoveryResult('in_attesa', [], 0);
            }

            // (D6) Any other live status (in_corso/in_valutazione/completato)
            // cannot be recovered.
            if ($participant->status !== 'errore') {
                throw new RecoveryRefused(RecoveryRefusalReason::NotFailed);
            }

            // (D5 guard 1, checked first — more informative) Scoring-stage
            // failure: an `evaluation` WebhookDelivery row already exists, so
            // BEAI already told the calling system this assessment is over.
            // No scoring-stage failure ever leaves an error session (guard 2
            // below), so this is the sole detection rule needed.
            $evaluationAlreadyDelivered = WebhookDelivery::where('participant_id', $participant->id)
                ->where('event_type', WebhookEventType::Evaluation)
                ->exists();

            if ($evaluationAlreadyDelivered) {
                throw new RecoveryRefused(RecoveryRefusalReason::EvaluationAlreadyDelivered);
            }

            // (D5 guard 2) No error session -> nothing an interview-stage
            // recovery can act on. Every interview-stage failure leaves
            // exactly one error session (createOrResumeSession() always runs
            // before issue()); this also catches the ZeroCompetencies
            // invariant case, which guard 1 would miss.
            $erroredSessions = InterviewSession::where('participant_id', $participant->id)
                ->where('status', 'error')
                ->get();

            if ($erroredSessions->isEmpty()) {
                throw new RecoveryRefused(RecoveryRefusalReason::NothingToRecover);
            }

            // (D2) Atomic reset — RESUME, not restart. Sessions at
            // completed/timeout/skipped are never touched.
            $participant->status = 'in_attesa';
            $participant->save();

            $competenciesReset = [];
            $utterancesDiscarded = 0;

            foreach ($erroredSessions as $session) {
                // (interview-continuous-flow D3) The reset now lives in a shared
                // action, so the automatic re-offer and this operator path cannot
                // drift apart. This path stays deliberately UNBOUNDED (D4): the
                // action does not consult `error_count`, and neither does the loop.
                $utterancesDiscarded += (new ResetSessionForRetry)($session);

                $competenciesReset[] = $session->competency_code;
            }

            // (D7) Interim structured log — explicitly NOT the ratified audit
            // trail (openspec/specs/audit-log/spec.md): not append-only, not
            // tenant-queryable, not retained, not policy-redacted. Default
            // `stack` channel; deliberately NO dedicated "audit" channel name,
            // which would invite false trust. No display_name/candidate_ref/
            // actor email (denylist redaction).
            Log::info('participant.recovered (INTERIM — NOT the ratified audit trail, openspec/specs/audit-log/spec.md)', [
                'participant_id' => $participant->id,
                'organization_id' => $participant->organization_id,
                'project_id' => $participant->project_id,
                'actor_user_id' => $actorUserId,
                'previous_status' => 'errore',
                'new_status' => 'in_attesa',
                'reason' => $reason,
                'competencies_reset' => $competenciesReset,
                'utterances_discarded' => $utterancesDiscarded,
                'at' => now()->toIso8601String(),
            ]);

            return new RecoveryResult('in_attesa', $competenciesReset, $utterancesDiscarded);
        });
    }
}
