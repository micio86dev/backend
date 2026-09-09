<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\Projects\ProjectWebhookDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * ProjectController (C4 Project Configuration).
 *
 * CRUD API for org-scoped Projects. Behind auth:api + TenantContext middleware.
 *
 * IMPORTANT: Do NOT use implicit route model binding for tenant-scoped models.
 * SubstituteBindings (group middleware) runs BEFORE TenantContext (also group, but
 * appended later), which means the global scope hasn't been initialized yet when
 * Laravel resolves `Project $project` type hints. We manually call findOrFail()
 * inside each action AFTER TenantContext has set the resolver — which guarantees
 * the TenantScoped global scope filters correctly and cross-org requests get 404.
 *
 * Routes:
 *   GET    /api/projects       — index (list all own-org projects)
 *   POST   /api/projects       — store (create + pin FV in transaction)
 *   GET    /api/projects/{project}  — show
 *   PATCH  /api/projects/{project}  — update (subject to immutability + lifecycle guards)
 *   DELETE /api/projects/{project}  — destroy (204 soft-delete; 409 while active)
 *
 * Pin atomicity (store):
 *   DB::transaction: Project::create → attach competencies → lockForUpdate FV →
 *   conditional is_locked flip → commit.
 *   No partial state on validation failure (validation runs before transaction).
 */
class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectWebhookDefaults $webhookDefaults,
    ) {}

    /**
     * GET /api/projects
     *
     * Returns all projects for the authenticated org (TenantScoped global scope applied).
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Project::class);

        // `avatarTemplate` eager-loaded because ProjectResource now renders the
        // template's name and provider: without it this list costs one extra
        // query per row, and ProjectAvatarTemplateTest counts them.
        $projects = Project::with(['frameworkVersion', 'competencies', 'avatarTemplate.llmModel'])->get();

        return ProjectResource::collection($projects);
    }

    /**
     * POST /api/projects
     *
     * Creates a Project and pins the FrameworkVersion (conditional is_locked flip).
     * All within a DB transaction — no partial state on failure.
     */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        // Plain Model — resolved explicitly, not via the TenantScoped global scope
        // (Organization carries no organization_id of its own; its id IS the tenant key).
        $organization = Organization::findOrFail($user->organization_id);

        $project = DB::transaction(function () use ($request, $organization): Project {
            $competencyIds = $request->input('competency_ids', []);
            if (! is_array($competencyIds)) {
                $competencyIds = [];
            }

            // Copy-on-create org webhook defaults (D3) — applied BEFORE create,
            // only for keys the request payload does not contain at all.
            $payload = $this->webhookDefaults->apply(
                $request->safe()->except(['competency_ids']),
                $request,
                $organization,
            );

            // Create the project — organization_id stamped by TenantScoped.creating
            $project = Project::create($payload);

            // Attach competencies with position pivot
            // See update(): `position` is the array key, so the list shape
            // matters. Validated at the request; normalised here too.
            $attach = [];
            foreach (array_values($competencyIds) as $position => $competencyId) {
                $attach[$competencyId] = ['position' => $position];
            }
            if (! empty($attach)) {
                $project->competencies()->attach($attach);
            }

            // Pin: lock-for-update the FV and conditionally flip is_locked
            $fv = FrameworkVersion::lockForUpdate()->findOrFail((int) $request->input('framework_version_id'));
            if (! $fv->is_locked) {
                $fv->is_locked = true;
                $fv->save();
            }

            return $project;
        });

        // Refresh before load(): Eloquent's create() only writes back the incrementing
        // key, not other DB-computed defaults (e.g. projects.webhook_events' jsonb
        // DEFAULT — C10 D10). Without this, a fresh POST with no webhook_events in the
        // payload would report null instead of the DB default in the response.
        $project->refresh();
        $project->load(['frameworkVersion', 'competencies', 'avatarTemplate.llmModel']);

        return (new ProjectResource($project))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/projects/{project}
     *
     * Returns a single project (TenantScoped — 404 for cross-org requests).
     * Project is resolved manually (not via route model binding) to ensure the
     * TenantScoped global scope is active (TenantContext runs before this method).
     */
    public function show(int $project): ProjectResource
    {
        $resolved = Project::with(['frameworkVersion', 'competencies', 'avatarTemplate.llmModel'])->findOrFail($project);

        $this->authorize('view', $resolved);

        return new ProjectResource($resolved);
    }

    /**
     * PATCH /api/projects/{project}
     *
     * Updates a project. Immutability and lifecycle guards enforced at FormRequest layer.
     * Model guard provides backstop for non-HTTP paths.
     * Project is resolved manually — see class docblock.
     */
    public function update(UpdateProjectRequest $request, int $project): JsonResponse
    {
        $resolved = Project::findOrFail($project);

        $competencyIds = $request->input('competency_ids');

        // ONE transaction, as `store()` already does. `sync()` is a detach
        // followed by an attach, so a failure between the scalar write and it
        // left the project's fields updated and its competency set half
        // rebuilt — the exact partial state the class docblock promises
        // cannot happen, on the one write path that was not holding to it.
        DB::transaction(function () use ($resolved, $request, $competencyIds): void {
            $resolved->update($request->safe()->except(['competency_ids']));

            if ($competencyIds === null) {
                return;
            }

            // `array_values`: `position` is the ARRAY KEY, and a payload of
            // `{"a": 12}` would send the string `a` into an unsignedInteger
            // pivot column — a 500 where a 422 belongs. The request rule now
            // refuses non-lists outright; this is the belt to that brace, and
            // it costs nothing.
            $attach = [];

            foreach (array_values((array) $competencyIds) as $position => $competencyId) {
                $attach[$competencyId] = ['position' => $position];
            }

            $resolved->competencies()->sync($attach);
        });

        $resolved->load(['frameworkVersion', 'competencies', 'avatarTemplate.llmModel']);

        return (new ProjectResource($resolved))->response();
    }

    /**
     * DELETE /api/projects/{project} — soft-delete, never while live.
     *
     * 204 on success; 409 while the project is `active`. The pinned
     * FrameworkVersion remains locked either way (soft-delete does not
     * unlock), and the project is resolved manually — see class docblock.
     *
     * NO `@scramble-return` here, deliberately. Annotating it overrode
     * per-path inference and published ONE 200 carrying the error body — the
     * 204 gone, the 409 invisible, and both Nuxt clients generated against a
     * response this endpoint never sends. `AvatarTemplateController::destroy`
     * has the same 204/409 shape, carries no annotation, and its spec is
     * right.
     *
     * The ARCHIVED rule is checked HERE rather than in the policy, and that
     * placement is the whole point: `Gate::before` returns true for a
     * superadmin and short-circuits every policy method, so a lifecycle
     * invariant written as a permission is one every superadmin skips without
     * noticing. Permission is `who`; this is `what state`.
     *
     * 409, not 403: the caller IS allowed to delete projects. This one is in
     * the wrong state, and telling an admin they lack permission would send
     * them to ask for a role they already have.
     *
     * The refused state is `active`, NOT "anything but archived", and the
     * difference is not cosmetic. Deleting a `draft` costs nothing — nobody
     * has been interviewed under it — while the same button on an `active`
     * project takes a live assessment away from candidates mid-interview, and
     * no confirmation dialog makes that recoverable.
     *
     * Demanding `archived` would have trapped every draft permanently: the
     * only approved transitions are `draft -> active` and
     * `active -> archived`, so the one route out of a mistyped draft would
     * have been to PUBLISH it to candidates first — which also freezes
     * `assessment_type` and `role_code` on the way past.
     */
    public function destroy(int $project): Response|JsonResponse
    {
        $resolved = Project::findOrFail($project);

        $this->authorize('delete', $resolved);

        if ($resolved->status === 'active') {
            return response()->json(
                ['message' => 'Archive the project before deleting it.', 'error' => 'project_is_active'],
                Response::HTTP_CONFLICT,
            );
        }

        $resolved->delete();

        return response()->noContent();
    }
}
