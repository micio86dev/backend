<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\ReusableLinks\CreateReusableInterviewLink;
use App\Actions\ReusableLinks\DisableReusableInterviewLink;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\ReusableInterviewLinkResource;
use App\Models\Project;
use App\Models\ReusableInterviewLink;
use App\Models\User;
use App\Policies\ParticipantPolicy;
use App\Support\Project\ProjectInterviewability;
use App\Support\PublicApi\PublicId;
use App\Support\Sso\EntryLinkMinter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * ReusableInterviewLinkController (reusable-interview-links, design AD-7).
 *
 * The admin surface of reusable interview links: create, list, disable, and
 * nothing else. There is deliberately no show, update, re-enable or "show the
 * URL again": a link's URL is shown exactly once, and a lost link means
 * creating a new one.
 *
 * Routes (auth:api + TenantContext), nested under the project:
 *   GET    /projects/{project}/reusable-links
 *   POST   /projects/{project}/reusable-links           (+ org.context)
 *   DELETE /projects/{project}/reusable-links/{link}    (+ org.context)
 *
 * Every operation runs in the same fixed order, 403 before 404, so a role check
 * that needs no model cannot leak whether a project exists in another tenant:
 *   1. `authorize('create', ParticipantPolicy::MODEL)` - admin and operator
 *      only, `viewer` denied. The model class-string is read off the policy's
 *      own constant for the architecture-guard reason documented there.
 *   2. `Project::findOrFail()`, scoped by `TenantContext`'s `TenantScoped`
 *      global scope: another organisation's id, a soft-deleted project and an
 *      unknown id are all 404. The project id is the INTEGER route id, resolved
 *      manually after the middleware (route-model binding would run before the
 *      tenant is known), exactly like `ProjectQuestionController`.
 *   3. (disable only) the link, by its PUBLIC id and inside the project: a
 *      malformed id, another project's link, another organization's link and an
 *      unknown id are all 404.
 *
 * Listing and disabling are not gated on the project's state: the links of a
 * closed project can still be seen and switched off.
 */
final class ReusableInterviewLinkController extends Controller
{
    public function __construct(
        private readonly EntryLinkMinter $minter,
        private readonly ProjectInterviewability $projectInterviewability,
        private readonly CreateReusableInterviewLink $createLink,
        private readonly DisableReusableInterviewLink $disableLink,
    ) {}

    /**
     * List the reusable interview links of a project.
     *
     * Returns every link of the project, disabled ones included, so the whole
     * set fits in one response. Active links come first, then the newest. A
     * link is described by its metadata only: the secret URL is never returned
     * again, and neither is the secret or anything derived from it.
     *
     * @param  int  $project  The id of the project.
     */
    #[Response(404, description: 'The project does not exist, was deleted, or belongs to another organization.', type: 'array{message: string}')]
    public function index(int $project): AnonymousResourceCollection
    {
        $this->authorize('create', ParticipantPolicy::MODEL);

        $project = Project::findOrFail($project);

        return ReusableInterviewLinkResource::collection(
            ReusableInterviewLink::query()
                ->where('project_id', $project->id)
                ->with('creator:id,name')
                // `disabled_at IS NOT NULL` is false (0) for an active link, so
                // ascending puts the active ones first.
                ->orderByRaw('disabled_at IS NOT NULL')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
        );
    }

    /**
     * Create a reusable interview link.
     *
     * Creates one non-expiring, revocable link for the project and returns its
     * entry URL. The URL is shown ONCE, in this response only: BEAI stores a
     * SHA-256 hash of the secret, never the secret, so it cannot be shown again
     * and a lost link means creating a new one. Each call creates a distinct
     * link. Every candidate who opens the URL starts their own interview in the
     * project, in the link's language, which is fixed at creation from the
     * project's language. Disable the link at any time to stop new interviews.
     *
     * Refused with 403 `entry_link_project_closed` while the project is not
     * open for interviews (not active, not yet live, or past its deadline) and
     * with 422 `PROJECT_NOT_INTERVIEWABLE` while it has no question for a
     * selected competency.
     *
     * @param  int  $project  The id of the project.
     */
    #[Response(404, description: 'The project does not exist, was deleted, or belongs to another organization.', type: 'array{message: string}')]
    #[Response(409, description: 'A superadmin must first select the organization to act for (`organization_context_required`).', type: 'array{message: string}')]
    public function store(Request $request, int $project): JsonResponse
    {
        $this->authorize('create', ParticipantPolicy::MODEL);

        $project = Project::findOrFail($project);

        // Inline and verbatim: Scramble derives the documented request body from
        // this exact call site.
        $validated = $request->validate([
            // An operator-facing name for the link (for example the trade-fair
            // stand it is meant for), shown in the link list and used to name the
            // candidates who open it. Optional; blank means none.
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        if (! $this->minter->projectIsAccessible($project)) {
            return response()->json(['message' => 'entry_link_project_closed'], 403);
        }

        $interviewability = $this->projectInterviewability->evaluate($project);
        if (! $interviewability['interviewable']) {
            return response()->json([
                'error' => 'PROJECT_NOT_INTERVIEWABLE',
                'competency_codes' => $interviewability['unsatisfied_competency_codes'],
            ], 422);
        }

        /** @var User $actor */
        $actor = $request->user();

        // Throws EntryLinkUrlNotConfigured (-> 500, bootstrap/app.php) when
        // CANDIDATE_APP_URL is unset, BEFORE any row is written.
        $created = $this->createLink->handle($project, $actor, $validated['label'] ?? null);

        return response()->json([
            'data' => new ReusableInterviewLinkResource($created->link),
            // The one and only carrier of the raw token. Never a bare `token`
            // field, never repeated by any later response.
            'entry_url' => $created->entryUrl,
        ], 201);
    }

    /**
     * Disable a reusable interview link.
     *
     * Stops the link from starting new interviews, immediately and for good: a
     * disabled link cannot be re-enabled, and anyone opening its URL is told it
     * does not exist. The link stays in the list, with its usage. Interviews
     * already started keep working until they finish, and the candidates who
     * took them are untouched. Responds 204 when the link is disabled, and also
     * when it already was: repeating the request changes nothing.
     *
     * @param  int  $project  The id of the project.
     * @param  string  $link  The public id of the link (`rlk_` followed by 26 characters).
     */
    #[Response(404, description: 'The project or the link does not exist, or belongs to another organization or project.', type: 'array{message: string}')]
    #[Response(409, description: 'A superadmin must first select the organization to act for (`organization_context_required`).', type: 'array{message: string}')]
    public function destroy(Request $request, int $project, string $link): JsonResponse
    {
        $this->authorize('create', ParticipantPolicy::MODEL);

        $project = Project::findOrFail($project);

        // A malformed id can never match a row: 404, never a validation error
        // that would tell a prober which part of the id was wrong.
        $bareId = PublicId::decode($link, ReusableInterviewLink::publicIdPrefix());
        abort_if($bareId === null, 404);

        $reusableLink = ReusableInterviewLink::query()
            ->where('project_id', $project->id)
            ->wherePublicId($bareId)
            ->firstOrFail();

        /** @var User $actor */
        $actor = $request->user();

        $this->disableLink->handle($reusableLink, $actor);

        // 204 whether this call disabled the link or it already was.
        return response()->json(null, 204);
    }
}
