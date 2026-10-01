<?php

declare(strict_types=1);

namespace App\Actions\ReusableLinks;

use App\Enums\ApiKeyMode;
use App\Events\ParticipantCreated;
use App\Models\Participant;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Services\ReusableLinkTokenGenerator;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\Project\ProjectInterviewability;
use App\Support\Sso\EntryLinkMinter;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RedeemReusableInterviewLink (reusable-interview-links, design AD-9, AD-10).
 *
 * Turns a presented link token into one fresh "visitor" participant, carrying
 * the self-declared, unverified name and email the visitor typed, and the
 * standard 120-minute candidate JWT. It runs on a PUBLIC endpoint, with no user
 * and no tenant context, so every decision below is made from the link row
 * itself; the only request values are the validated, normalized identity.
 *
 * ORDER (security-critical):
 *   1. Format, with no database: a value that is not exactly a token this
 *      generator could have produced is "not found" before anything is read.
 *   2. Lookup by the SHA-256 of the token, of an ENABLED link, lifting the named
 *      `tenant` scope (the one place allowed to; an architecture test pins it)
 *      because the organisation is not known yet. `hash_equals()` is a belt over
 *      the database equality.
 *   3. Everything after runs inside `TenantContextScope::runFor(link.org)`.
 *   4. The project, pinned to the link's organisation, soft deletes kept: gone
 *      is "not found", exactly like an unknown token.
 *   5. The project gates, OUTSIDE the lock: `EntryLinkMinter::projectIsAccessible`
 *      and `ProjectInterviewability::isInterviewable`, evaluated fresh. A failure
 *      is `Refused` and consumes nothing. They run before the lock to keep the
 *      locked section short; a project that closes between this check and the
 *      commit is the same window `/sso/exchange` has, and `/start` re-checks.
 *   6. ONE transaction: re-select the link `FOR UPDATE`, and if it was disabled in
 *      the meantime answer "not found" (a committed Disable always wins); if the
 *      visitor's email is already enrolled in the project answer "duplicate"
 *      (nothing written); otherwise count the use, create the visitor, mint the
 *      credential. The mint is INSIDE so a failure rolls everything back: no
 *      visitor, no counter, no event. A `(project_id, email)` unique violation
 *      at the insert (a race the link lock cannot serialise) is the same
 *      "duplicate".
 *   7. After the commit, `ParticipantCreated`, exactly as the SSO exchange fires
 *      it for a new candidate: the progress webhook, then scoring, dashboards and
 *      exports all treat the visitor like any other participant.
 *
 * The visitor is always an INSERT, never an upsert, and is never a resume of an
 * existing participant: a fresh ULID candidate reference per redemption means
 * `(project_id, candidate_ref)` can never collide. The `(project_id, email)`
 * index can, because the email is now the visitor's own: a later slice refuses
 * that case instead of merging into the existing enrolment.
 *
 * Never logs the token, its hash, the name, the email or the request body.
 */
final class RedeemReusableInterviewLink
{
    public function __construct(
        private readonly EntryLinkMinter $minter,
        private readonly ProjectInterviewability $projectInterviewability,
    ) {}

    /**
     * @param  mixed  $rawToken  the value of the request's `link_token` field, whatever it is
     * @param  VisitorIdentity  $identity  the validated name and email the visitor typed
     */
    public function handle(mixed $rawToken, VisitorIdentity $identity): RedemptionOutcome
    {
        $hash = ReusableLinkTokenGenerator::hashIfWellFormed($rawToken);

        if ($hash === null) {
            return RedemptionOutcome::notFound();
        }

        $link = ReusableInterviewLink::withoutGlobalScope('tenant')
            ->where('token_hash', $hash)
            ->whereNull('disabled_at')
            ->first();

        if ($link === null || ! hash_equals($link->token_hash, $hash)) {
            return RedemptionOutcome::notFound();
        }

        return TenantContextScope::runFor(
            $link->organization_id,
            fn (): RedemptionOutcome => $this->redeem($link, $hash, $identity),
        );
    }

    /**
     * Everything after the lookup, inside the link's own tenant context.
     */
    private function redeem(ReusableInterviewLink $link, string $hash, VisitorIdentity $identity): RedemptionOutcome
    {
        // The project is pinned to the link's organisation explicitly, never
        // read from ambient state, and keeps `SoftDeletes`: a deleted project
        // is "not found", the same answer an unknown token gets.
        $project = Project::withoutGlobalScope('tenant')
            ->where('organization_id', $link->organization_id)
            ->find($link->project_id);

        if ($project === null) {
            return RedemptionOutcome::notFound();
        }

        if (! $this->minter->projectIsAccessible($project)
            || ! $this->projectInterviewability->isInterviewable($project)) {
            return RedemptionOutcome::refused($project);
        }

        try {
            $created = DB::transaction(function () use ($link, $hash, $project, $identity): array|RedemptionStatus {
                // The decision is made on the row as it is NOW, under the same
                // lock a Disable takes: either this redemption completes before
                // the Disable commits, or it sees `disabled_at` and creates
                // nothing. Through the tenant scope (the context is the link's),
                // by id and hash.
                $locked = ReusableInterviewLink::query()
                    ->whereKey($link->getKey())
                    ->where('token_hash', $hash)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null || $locked->disabled_at !== null) {
                    return RedemptionStatus::NotFound;
                }

                // The email is the visitor's own and unverified, so an address
                // that is already enrolled in this project is a refusal, never a
                // resume. It sits AFTER the disabled re-check (a disabled link
                // answers the generic 404, never a 409) and BEFORE any write
                // (a refusal leaves the counter and the timestamp alone). The
                // lock serialises two submits through THIS link; the unique
                // index below catches the ones it cannot.
                if ($this->emailIsAlreadyEnrolled($project, $identity)) {
                    return RedemptionStatus::Duplicate;
                }

                $number = $locked->uses_count + 1;

                $locked->forceFill([
                    'uses_count' => $number,
                    'last_used_at' => now(),
                ])->save();

                $participant = $this->createVisitor($locked, $project, $identity);

                return [$participant, CandidateTokenFactory::mintCandidateToken($participant)];
            });
        } catch (QueryException $e) {
            // Two redemptions through DIFFERENT links, or an operator or API
            // enrolment, are not serialised by one link row: the unique index is
            // what decides. The transaction above has already rolled back (the
            // counter and the insert are both undone) and no event has fired.
            // The exception is consumed, never logged or reported: its message
            // carries the bound address.
            if (self::isDuplicateEmail($e)) {
                return RedemptionOutcome::duplicate();
            }

            throw $e;
        }

        if ($created === RedemptionStatus::NotFound) {
            return RedemptionOutcome::notFound();
        }

        if ($created === RedemptionStatus::Duplicate) {
            return RedemptionOutcome::duplicate();
        }

        [$participant, $accessToken] = $created;

        // After the commit: a listener must never see a visitor that a later
        // rollback could remove.
        event(new ParticipantCreated($participant->id, $project->id));

        return RedemptionOutcome::redeemed($accessToken);
    }

    /**
     * Whether `$identity`'s address is already enrolled in `$project`, whatever
     * the case another path stored it in (the visitor path stores lower case,
     * the operator and API paths keep what they were given).
     *
     * `Participant` has no soft deletes and no global scope, so the query sees
     * every row; the organisation and the project are explicit, as in the public
     * API's enrolment.
     */
    private function emailIsAlreadyEnrolled(Project $project, VisitorIdentity $identity): bool
    {
        return Participant::query()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->whereRaw('lower(email) = ?', [$identity->email])
            ->exists();
    }

    /**
     * Whether a database error is the `(project_id, email)` unique violation and
     * nothing else: the SQLSTATE `unique_violation` AND the index name, so a
     * different error that happens to mention the index is never mapped.
     */
    private static function isDuplicateEmail(QueryException $e): bool
    {
        return $e->getCode() === '23505'
            && str_contains($e->getMessage(), 'participants_project_id_email_unique');
    }

    /**
     * Insert the visitor. Every value comes from the link, the project or the
     * validated identity, through `forceFill()`: the model's `$fillable` does not
     * allow any of the identity, tenancy or origin columns, and the only request
     * values that reach here are the normalized name and email.
     */
    private function createVisitor(ReusableInterviewLink $link, Project $project, VisitorIdentity $identity): Participant
    {
        $candidateRef = 'rlv_'.Str::ulid();

        $participant = (new Participant)->forceFill([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'candidate_ref' => $candidateRef,
            'email' => $identity->email,
            'display_name' => $identity->displayName,
            'role_code' => $project->assessment_type === 'standard' ? $project->role_code : null,
            'language' => $link->lang,
            'mode' => ApiKeyMode::Live,
            'status' => 'in_attesa',
            'reusable_interview_link_id' => $link->getKey(),
        ]);

        $participant->save();

        return $participant;
    }
}
