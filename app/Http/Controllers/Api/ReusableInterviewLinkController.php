<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\ReusableLinks\CreateReusableInterviewLink;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\ReusableInterviewLinkResource;
use App\Models\Project;
use App\Models\User;
use App\Policies\ParticipantPolicy;
use App\Support\Project\ProjectInterviewability;
use App\Support\Sso\EntryLinkMinter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ReusableInterviewLinkController (reusable-interview-links, design AD-7).
 *
 * The admin surface of reusable interview links: create, list, disable, and
 * nothing else. There is deliberately no show, update, re-enable or "show the
 * URL again": a link's URL is shown exactly once, and a lost link means
 * creating a new one.
 *
 * Routes (auth:api + TenantContext), nested under the project:
 *   POST   /projects/{project}/reusable-links           (+ org.context)
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
 */
final class ReusableInterviewLinkController extends Controller
{
    public function __construct(
        private readonly EntryLinkMinter $minter,
        private readonly ProjectInterviewability $projectInterviewability,
        private readonly CreateReusableInterviewLink $createLink,
    ) {}

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
}
