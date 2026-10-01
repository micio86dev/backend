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
use App\Support\Participant\PlaceholderEmail;
use App\Support\Project\ProjectInterviewability;
use App\Support\Sso\EntryLinkMinter;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RedeemReusableInterviewLink (reusable-interview-links, design AD-9, AD-10).
 *
 * Turns a presented link token into one fresh, anonymous "visitor"
 * participant and the standard 120-minute candidate JWT. It runs on a PUBLIC
 * endpoint, with no user and no tenant context, so every decision below is
 * made from the link row itself and nothing is read from the request.
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
 *      the meantime answer "not found" (a committed Disable always wins), count
 *      the use, create the visitor, mint the credential. The mint is INSIDE so a
 *      failure rolls everything back: no visitor, no counter, no event.
 *   7. After the commit, `ParticipantCreated`, exactly as the SSO exchange fires
 *      it for a new candidate: the progress webhook, then scoring, dashboards and
 *      exports all treat the visitor like any other participant.
 *
 * The visitor is always an INSERT, never an upsert: a fresh ULID candidate
 * reference per redemption means neither unique index on `participants`
 * (`(project_id, candidate_ref)`, `(project_id, email)`) can ever collide, so no
 * redemption can merge into, overwrite or hijack an existing participant.
 *
 * Never logs the token, its hash or the request body.
 */
final class RedeemReusableInterviewLink
{
    /**
     * The name a visitor takes when the link has no label ("Reusable link #3").
     */
    public const DEFAULT_LABEL = 'Reusable link';

    public function __construct(
        private readonly EntryLinkMinter $minter,
        private readonly ProjectInterviewability $projectInterviewability,
    ) {}

    /**
     * @param  mixed  $rawToken  the value of the request's `link_token` field, whatever it is
     */
    public function handle(mixed $rawToken): RedemptionOutcome
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
            fn (): RedemptionOutcome => $this->redeem($link, $hash),
        );
    }

    /**
     * Everything after the lookup, inside the link's own tenant context.
     */
    private function redeem(ReusableInterviewLink $link, string $hash): RedemptionOutcome
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

        /** @var array{0: Participant, 1: string}|null $created */
        $created = DB::transaction(function () use ($link, $hash, $project): ?array {
            // The decision is made on the row as it is NOW, under the same lock
            // a Disable takes: either this redemption completes before the
            // Disable commits, or it sees `disabled_at` and creates nothing.
            // Through the tenant scope (the context is the link's), by id and
            // hash.
            $locked = ReusableInterviewLink::query()
                ->whereKey($link->getKey())
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->disabled_at !== null) {
                return null;
            }

            $number = $locked->uses_count + 1;

            $locked->forceFill([
                'uses_count' => $number,
                'last_used_at' => now(),
            ])->save();

            $participant = $this->createVisitor($locked, $project, $number);

            return [$participant, CandidateTokenFactory::mintCandidateToken($participant)];
        });

        if ($created === null) {
            return RedemptionOutcome::notFound();
        }

        [$participant, $accessToken] = $created;

        // After the commit: a listener must never see a visitor that a later
        // rollback could remove.
        event(new ParticipantCreated($participant->id, $project->id));

        return RedemptionOutcome::redeemed($accessToken);
    }

    /**
     * Insert the visitor. Every value comes from the link or the project,
     * through `forceFill()`: the model's `$fillable` does not allow any of the
     * identity, tenancy or origin columns, and no request value reaches here.
     */
    private function createVisitor(ReusableInterviewLink $link, Project $project, int $number): Participant
    {
        $candidateRef = 'rlv_'.Str::ulid();

        $participant = (new Participant)->forceFill([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'candidate_ref' => $candidateRef,
            'email' => PlaceholderEmail::for($candidateRef),
            'display_name' => ($link->label ?? self::DEFAULT_LABEL).' #'.$number,
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
