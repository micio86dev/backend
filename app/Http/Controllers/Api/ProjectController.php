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
 *   DELETE /api/projects/{project}  — destroy (204, soft-delete)
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
            $attach = [];
            foreach ($competencyIds as $position => $competencyId) {
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
        $project->load(['frameworkVersion', 'competencies']);

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
        $resolved = Project::with(['frameworkVersion', 'competencies'])->findOrFail($project);

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

        $resolved->update($request->safe()->except(['competency_ids']));

        // Update competency pivot if provided
        if ($competencyIds !== null) {
            $attach = [];
            foreach ((array) $competencyIds as $position => $competencyId) {
                $attach[$competencyId] = ['position' => $position];
            }
            $resolved->competencies()->sync($attach);
        }

        $resolved->load(['frameworkVersion', 'competencies']);

        return (new ProjectResource($resolved))->response();
    }

    /**
     * DELETE /api/projects/{project}
     *
     * Soft-deletes the project. Returns HTTP 204 No Content.
     * The pinned FrameworkVersion remains locked (soft-delete does not unlock).
     * Project is resolved manually — see class docblock.
     */
    public function destroy(int $project): Response
    {
        $resolved = Project::findOrFail($project);

        $this->authorize('delete', $resolved);

        $resolved->delete();

        return response()->noContent();
    }
}
