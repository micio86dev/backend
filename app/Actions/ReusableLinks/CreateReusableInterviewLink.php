<?php

declare(strict_types=1);

namespace App\Actions\ReusableLinks;

use App\Exceptions\Sso\EntryLinkUrlNotConfigured;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Models\User;
use App\Services\ReusableLinkTokenGenerator;
use App\Support\Audit\AuditRecorder;
use App\Support\PublicApi\PublicId;
use App\Support\Sso\EntryLinkUrlComposer;
use App\Support\Tenancy\TenantResolver;
use LogicException;

/**
 * CreateReusableInterviewLink (reusable-interview-links, design AD-6).
 *
 * Creates one non-expiring, revocable link bound to the organisation and the
 * project of an already-resolved `Project`. Order matters and is the point of
 * this class:
 *
 *   1. resolve the language (`project.language`, frozen on the row);
 *   2. generate the token;
 *   3. compose the entry URL BEFORE anything is written, so a missing
 *      `CANDIDATE_APP_URL` fails loud with no row left behind: a stored link
 *      whose token was never disclosed can never be redeemed by anyone;
 *   4. insert the row with the HASH and the display prefix only;
 *   5. audit, after the write, so the trail never claims a link that did not
 *      commit.
 *
 * The raw token lives only in this method's locals and in the returned URL. It
 * is never handed to the audit recorder, a logger or a queue.
 *
 * Binding is never read from input: `organization_id` and `project_id` come
 * from the project, `created_by` from the actor, and every one of them is
 * written through `forceFill()` because only `label` is mass-assignable.
 */
final class CreateReusableInterviewLink
{
    public function __construct(
        private readonly EntryLinkUrlComposer $composer,
        private readonly AuditRecorder $audit,
        private readonly TenantResolver $tenant,
    ) {}

    /**
     * @throws EntryLinkUrlNotConfigured When the candidate app origin is not configured; nothing is stored.
     * @throws LogicException When the project does not belong to the tenant context the row would be stamped with.
     */
    public function handle(Project $project, User $actor, ?string $label): CreatedReusableLink
    {
        // `TenantScoped` stamps the row with the resolver's organisation. For an
        // ordinary caller that is the project's by construction; asserting it
        // here, BEFORE the insert, turns a mismatch into a refusal instead of a
        // link whose organisation and project disagree.
        if ($this->tenant->getOrgId() !== $project->organization_id) {
            throw new LogicException('A reusable link must be created inside its project\'s own tenant context.');
        }

        $lang = $project->language ?? config('app.fallback_locale', 'en');

        $rawToken = ReusableLinkTokenGenerator::generate();
        $entryUrl = $this->composer->composeReusable($rawToken, $lang);

        $link = new ReusableInterviewLink;
        $link->forceFill([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'created_by' => $actor->getKey(),
            'label' => $this->normaliseLabel($label),
            'lang' => $lang,
            'token_hash' => ReusableLinkTokenGenerator::hash($rawToken),
            'token_prefix' => ReusableLinkTokenGenerator::prefixOf($rawToken),
            // Explicit, so the returned model reads the values the database
            // default would have supplied without a second query.
            'uses_count' => 0,
            'last_used_at' => null,
            'disabled_at' => null,
            'disabled_by' => null,
        ]);
        $link->save();
        $link->setRelation('creator', $actor);

        // Names, never values, of anything secret: the project and link public
        // ids identify the subject, and the prefix is the deliberately visible
        // identification aid. The token and its hash are not passed at all.
        $this->audit->record(
            action: 'reusable_link.created',
            subjectType: 'reusable_interview_link',
            subjectId: (int) $link->getKey(),
            after: [
                'id' => PublicId::encode($link),
                'project_id' => PublicId::encode($project),
                'label' => $link->label,
                'lang' => $link->lang,
                'token_prefix' => $link->token_prefix,
            ],
        );

        return new CreatedReusableLink($link, $entryUrl);
    }

    /**
     * A label is stored trimmed; an empty or whitespace-only one is no label.
     */
    private function normaliseLabel(?string $label): ?string
    {
        $trimmed = $label === null ? '' : trim($label);

        return $trimmed === '' ? null : $trimmed;
    }
}
