<?php

declare(strict_types=1);

namespace App\Actions\Participant;

use App\Actions\InterviewSession\ResetSessionForRetry;
use App\Enums\ApiKeyMode;
use App\Enums\EvaluationStatus;
use App\Exceptions\Participant\EvaluationRetryRefusalReason;
use App\Exceptions\Participant\EvaluationRetryRefused;
use App\Exceptions\Sso\EntryLinkRefusalReason;
use App\Exceptions\Sso\EntryLinkRefused;
use App\Models\Competency;
use App\Models\CompetencyResult;
use App\Models\Evaluation;
use App\Models\InterviewSession;
use App\Models\Participant;
use App\Models\Project;
use App\Support\Audit\AuditRecorder;
use App\Support\Participant\PlaceholderEmail;
use App\Support\Sso\EntryLinkMinter;
use App\Support\Sso\EntryLinkUrlComposer;
use App\Support\Sso\LinkDelivery;
use App\Support\Sso\MintedEntryLink;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * AuthorizeEvaluationRetry (scoring-retry-rt-b, design D4/D5).
 *
 * The SOLE writer of the `completato -> in_attesa` edge (an arch test pins it:
 * `tests/Arch/RetryEdgeWriterArchTest`). It authorizes the single re-interview
 * of a `pending` evaluation for both HTTP surfaces (operator and M2M, wired in
 * a later slice) with no decision logic duplicated outside this class.
 *
 * Inside one `DB::transaction` holding `FOR UPDATE` on the participant row it
 * checks the refusal guards in the design's order, flips the participant to
 * `in_attesa`, resets the sessions of the INVALID competencies (the project's
 * current composition minus the codes holding a valid result) with
 * `ResetSessionForRetry`, flags the Evaluation (`retry_attempt`,
 * `retry_authorized_at`) and mints the single-use link. The link is minted INSIDE
 * the transaction on purpose: a project that closed between the guard and the
 * mint then rolls the whole authorization back, so "authorized" and "a link
 * exists" are atomic. The valid competencies' sessions, utterances and every
 * `CompetencyResult` row are left untouched: the evaluation stays `pending` and
 * unreadable (the read gate needs `completato`) until the re-interview is scored.
 *
 * The link is minted from the locked participant row, never from request input
 * (D5): the exchange upserts identity columns from the token's claims, so claims
 * equal to the row make that rewrite a no-op.
 *
 * LINK LIFETIME FOLLOWS THE DELIVERY CHANNEL (owner resolution I9, overriding the
 * specs' "always 24 hours"): the link lives 24 hours (`LinkDelivery::Emailed`)
 * only when BEAI can email it, i.e. the address is a real one and the row is not
 * a reusable-link visitor. A retry for a placeholder or purged address or a
 * visitor is returned to the authorizer only and stays at 30 minutes, exactly
 * like every other returned link. `EntryLinkMinter` downgrades a visitor itself.
 * No email is queued by this slice (`emailSent` is false); the retry email will be
 * queued under the same two conditions (real address, not a visitor).
 *
 * The finalize trigger dedup key is attempt-scoped (`finalize:{pid}:retry`, read
 * from the Evaluation row by `FinalizeInterview`), so this action never touches
 * the cache (design D7, I6).
 *
 * The interim log line and the `AuditRecorder` row are written AFTER the
 * transaction commits, like `DuplicateAvatarTemplate` and
 * `DisableReusableInterviewLink`: `AuditRecorder` swallows its own exceptions,
 * and a Postgres error raised inside a transaction aborts it, so a failing audit
 * insert in the middle of the transaction would poison the work it was recording.
 * After commit the trail can never claim an authorization that did not commit,
 * and a lost row can never roll the authorization back. Neither is written on a
 * refusal or a rollback.
 *
 * A `user` actor MUST carry the authorizer's user id: an authorization nobody
 * answers for would leave an audit row with no authorizer, so it is refused
 * before any write. The M2M actor always carries its client id (`AuditRecorder`
 * reads `Auth::user()`, null on that surface, so the client id travels in the
 * payload).
 *
 * REQ: Evaluation Retry Authorization Action,
 *      Evaluation Retry Refusal Guards,
 *      Interim Retry Audit Logging
 *      (openspec/changes/scoring-retry-rt-b/specs/participant-sso/spec.md)
 */
final class AuthorizeEvaluationRetry
{
    public function __construct(
        private readonly EntryLinkMinter $minter,
        private readonly EntryLinkUrlComposer $urlComposer,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @throws InvalidArgumentException When a `user` actor carries no user id
     *                                  (nothing is written).
     * @throws ModelNotFoundException When the participant does not belong to
     *                                `$organizationId` (-> 404, nothing is written).
     * @throws EvaluationRetryRefused When a guard refuses the retry (-> 409).
     */
    public function handle(int $participantId, int $organizationId, RetryActor $actor, ?string $reason): RetryAuthorization
    {
        if ($actor->type === RetryActor::TYPE_USER && $actor->userId === null) {
            throw new InvalidArgumentException('A user actor must carry the authorizing user id: an authorization without an authorizer is not recorded.');
        }

        // Every tenant-scoped read below (evaluation, sessions, results, the
        // project and its competencies, the audit row) is scoped to the target
        // organization by construction, whatever the caller's ambient context.
        return TenantContextScope::runFor($organizationId, function () use ($participantId, $organizationId, $actor, $reason): RetryAuthorization {
            $outcome = DB::transaction(fn (): array => $this->authorize($participantId, $organizationId));

            $this->record($outcome, $actor, $reason);

            return $outcome['authorization'];
        });
    }

    /**
     * @return array{authorization: RetryAuthorization, participant: Participant, evaluation: Evaluation}
     */
    private function authorize(int $participantId, int $organizationId): array
    {
        // Org filter + row lock in one query: a cross-organization id 404s here,
        // before any status is read. Everything below is decided on the row as it
        // is NOW, under the lock.
        $participant = Participant::where('organization_id', $organizationId)
            ->lockForUpdate()
            ->findOrFail($participantId);

        $evaluation = Evaluation::where('participant_id', $participant->id)->first();
        $project = $participant->project;

        $this->guard($participant, $evaluation, $project);

        // guard() throws unless both exist; the narrowing is for the analyser.
        assert($evaluation instanceof Evaluation && $project instanceof Project);

        // THE EDGE: completato -> in_attesa, validated by the model's own guard.
        $participant->status = 'in_attesa';
        $participant->save();

        $competenciesReset = $this->resetInvalidSessions($participant, $evaluation, $project);

        $evaluation->forceFill(['retry_attempt' => true, 'retry_authorized_at' => now()])->save();

        $minted = $this->mintLink($participant, $project);

        $authorization = new RetryAuthorization(
            status: 'in_attesa',
            entryUrl: $this->urlComposer->compose($minted->token, $minted->lang),
            expiresAt: $minted->expiresAt,
            emailSent: false,
            competenciesReset: $competenciesReset,
        );

        return ['authorization' => $authorization, 'participant' => $participant, 'evaluation' => $evaluation];
    }

    /**
     * The refusal guards, in the design's order. `retry_already_consumed` is
     * first on purpose: a participant mid-retry is `in_attesa`, so a status-first
     * order would answer a second authorization `not_completed`.
     *
     * @throws EvaluationRetryRefused
     */
    private function guard(Participant $participant, ?Evaluation $evaluation, ?Project $project): void
    {
        if ($evaluation?->retry_attempt === true) {
            throw new EvaluationRetryRefused(EvaluationRetryRefusalReason::RetryAlreadyConsumed);
        }

        if ($participant->status !== 'completato') {
            throw new EvaluationRetryRefused(EvaluationRetryRefusalReason::NotCompleted);
        }

        // A test-mode participant is never scored by the real job
        // (`DispatchScoringJob` skips it): a retry would strand it.
        if ($participant->mode === ApiKeyMode::Test) {
            throw new EvaluationRetryRefused(EvaluationRetryRefusalReason::TestModeParticipant);
        }

        if ($evaluation === null || $evaluation->status !== EvaluationStatus::Pending) {
            throw new EvaluationRetryRefused(EvaluationRetryRefusalReason::EvaluationNotPending);
        }

        // A soft-deleted project resolves to null through the relation.
        if ($project === null || ! $this->minter->projectIsAccessible($project)) {
            throw new EvaluationRetryRefused(EvaluationRetryRefusalReason::ProjectInaccessible);
        }
    }

    /**
     * Reset the session of every invalid competency that has one.
     *
     * Invalid = the project's CURRENT composition minus the codes holding a
     * `valid = true` result (a competency with an invalid result, or none, is
     * re-interviewed). A competency without a session has nothing to reset and is
     * not reported.
     *
     * @return list<string> the codes whose session was reset
     */
    private function resetInvalidSessions(Participant $participant, Evaluation $evaluation, Project $project): array
    {
        $composition = $project->competencies()->get()
            ->map(fn (Competency $competency): string => (string) $competency->code)
            ->all();

        $validCodes = CompetencyResult::where('evaluation_id', $evaluation->id)
            ->where('valid', true)
            ->pluck('competency_code')
            ->map(fn (mixed $code): string => (string) $code)
            ->all();

        $invalidCodes = array_values(array_diff($composition, $validCodes));

        $reset = [];
        foreach (InterviewSession::where('participant_id', $participant->id)->whereIn('competency_code', $invalidCodes)->get() as $session) {
            (new ResetSessionForRetry)($session);
            $reset[] = (string) $session->competency_code;
        }

        return $reset;
    }

    /**
     * Mint the single-use link from the participant row (never from input).
     *
     * @throws EvaluationRetryRefused When the entry gates refuse the mint (the
     *                                project closed since the guard): the caller's transaction rolls back.
     * @throws EntryLinkRefused Any other mint refusal is a defect signal (the row
     *                          is not terminal and the role is its own) and is not disguised as a refusal.
     */
    private function mintLink(Participant $participant, Project $project): MintedEntryLink
    {
        try {
            return $this->minter->mint(
                $project,
                $participant->candidate_ref,
                $participant->display_name,
                $participant->email,
                $participant->role_code,
                $participant->language,
                delivery: $this->hasDeliverableAddress($participant) ? LinkDelivery::Emailed : LinkDelivery::Returned,
            );
        } catch (EntryLinkRefused $refused) {
            if ($refused->reason === EntryLinkRefusalReason::Gates) {
                throw new EvaluationRetryRefused(EvaluationRetryRefusalReason::ProjectInaccessible);
            }

            throw $refused;
        }
    }

    /**
     * Whether the address is a real one a person gave us, not a synthesised
     * placeholder (legacy or purged). A reusable-link visitor is NOT checked here:
     * `EntryLinkMinter` downgrades an `Emailed` request for a visitor to `Returned`
     * itself, so that rule has one owner (design D1).
     */
    private function hasDeliverableAddress(Participant $participant): bool
    {
        return ! PlaceholderEmail::is($participant->email);
    }

    /**
     * The interim log line and the audit row, once the transaction has committed.
     * Neither carries the email, the display name, the candidate reference, the
     * link or the token.
     *
     * @param  array{authorization: RetryAuthorization, participant: Participant, evaluation: Evaluation}  $outcome
     */
    private function record(array $outcome, RetryActor $actor, ?string $reason): void
    {
        ['authorization' => $authorization, 'participant' => $participant, 'evaluation' => $evaluation] = $outcome;

        $actorContext = [
            'actor_type' => $actor->type,
            'actor_user_id' => $actor->userId,
            'actor_api_client_id' => $actor->apiClientId,
        ];

        // INTERIM — explicitly NOT the ratified audit trail, same limits as
        // `participant.recovered`: no dedicated "audit" channel name, which would
        // invite false trust.
        Log::info('participant.retry_authorized (INTERIM — NOT the ratified audit trail, openspec/specs/audit-log/spec.md)', [
            ...$actorContext,
            'participant_id' => $participant->id,
            'organization_id' => $participant->organization_id,
            'project_id' => $participant->project_id,
            'previous_status' => 'completato',
            'new_status' => 'in_attesa',
            'reason' => $reason,
            'competencies_reset' => $authorization->competenciesReset,
            'email_queued' => $authorization->emailSent,
            'at' => now()->toIso8601String(),
        ]);

        $this->audit->record(
            action: 'evaluation.retry_authorized',
            subjectType: 'evaluation',
            subjectId: $evaluation->id,
            after: [
                'participant_id' => $participant->id,
                'evaluation_id' => $evaluation->id,
                'competencies_reset' => $authorization->competenciesReset,
                'reason' => $reason,
                ...$actorContext,
            ],
        );
    }
}
