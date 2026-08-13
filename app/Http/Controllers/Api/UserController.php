<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\Admin\UserResource;
use App\Models\Organization;
use App\Models\User;
use App\Support\Users\UserAdminReader;
use App\Support\Users\UserGuards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * UserController (backoffice-missing-pages D4 — user-management).
 *
 * Admin-only, org-scoped user CRUD + Spatie role assignment. This is a
 * privilege-escalation surface: every action reaches a User exclusively
 * through UserAdminReader (org filter + is_superadmin=false, D4) — never a
 * bare `User::` static call — and every last-admin-affecting write routes
 * through UserGuards::ensureAdminSurvivesThenMutate() (D4), which is the
 * ONLY place role assignment (`syncRoles()` + `forgetCachedPermissions()`)
 * and deactivation happen.
 *
 * Routes (all under auth:api + TenantContext, routes/api.php):
 *   GET    /users               — index (org-scoped, includes deactivated)
 *   POST   /users               — store (admin sets the initial password)
 *   PATCH  /users/{id}          — update (name/email/role/password)
 *   POST   /users/{id}/deactivate — 204, D5
 *   POST   /users/{id}/activate   — 204, D5
 *
 * Deliberately absent: GET /api/roles (D4 — the allow-list is a code-level
 * enum, exported to openapi.json, never a runtime endpoint) and DELETE (D5 —
 * a DELETE that does not delete would lie about what it does).
 */
class UserController extends Controller
{
    public function __construct(
        private readonly UserAdminReader $reader,
        private readonly UserGuards $guards,
    ) {}

    /**
     * GET /api/users
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $users = $this->reader->listQuery()->orderBy('name')->get();

        return UserResource::collection($users);
    }

    /**
     * POST /api/users
     *
     * `organization_id` and `is_superadmin` are never read from the request
     * at all — the org comes from TenantContext via the authenticated
     * caller, and is_superadmin is always false for a user created here.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        /** @var User $currentUser */
        $currentUser = $request->user();
        $orgId = $this->requireOrgId($currentUser);

        $user = new User($request->safe()->only(['name', 'email', 'password']));
        $user->organization()->associate(Organization::findOrFail($orgId));
        $user->is_superadmin = false;
        $user->save();

        $this->assignRole($user, $orgId, (string) $request->validated('role'));

        return (new UserResource($user->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /api/users/{id}
     *
     * A role change that would remove the target's admin status routes
     * through UserGuards — the ONLY place a role is ever written on this
     * surface — so the last-admin / self-demotion invariants can never be
     * bypassed by a future call site.
     */
    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();
        $orgId = $this->requireOrgId($currentUser);
        $target = $this->reader->read($id);

        if ($request->has('role')) {
            $newRole = (string) $request->validated('role');
            $currentRole = $target->getRoleNames()->first();
            $targetLosesAdminStatus = $currentRole === 'admin' && $newRole !== 'admin';

            $this->guards->ensureAdminSurvivesThenMutate(
                actor: $currentUser,
                target: $target,
                targetLosesAdminStatus: $targetLosesAdminStatus,
                selfErrorCode: 'self_demotion',
                mutate: function () use ($target, $orgId, $newRole): void {
                    $this->assignRole($target, $orgId, $newRole);
                },
            );
        }

        $target->update($request->safe()->only(['name', 'email', 'password']));

        return (new UserResource($target->fresh()))->response();
    }

    /**
     * POST /api/users/{id}/deactivate
     *
     * 204 No Content. Soft deactivation only — the row survives so
     * audit-relevant authorship survives (D5).
     */
    public function deactivate(int $id): Response
    {
        $target = $this->reader->read($id);

        $this->authorize('deactivate', $target);

        /** @var User $currentUser */
        $currentUser = request()->user();

        $this->guards->ensureAdminSurvivesThenMutate(
            actor: $currentUser,
            target: $target,
            targetLosesAdminStatus: $target->hasRole('admin'),
            selfErrorCode: 'self_deactivation',
            mutate: function () use ($target): void {
                $target->deactivated_at = now();
                $target->save();
            },
        );

        return response()->noContent();
    }

    /**
     * POST /api/users/{id}/activate
     *
     * 204 No Content. Never guarded by UserGuards — activation only ever
     * ADDS an available admin/operator/viewer back to the org, so it cannot
     * violate the last-admin invariant.
     */
    public function activate(int $id): Response
    {
        $target = $this->reader->read($id);

        $this->authorize('activate', $target);

        $target->deactivated_at = null;
        $target->save();

        return response()->noContent();
    }

    /**
     * This surface is admin-only (UserPolicy) — an admin role only exists
     * scoped to a non-null organization_id (Spatie teams mode), so a caller
     * who passed the `create`/`update` policy check always has one. This
     * turns that structural guarantee into an explicit, typed fact rather
     * than a nullable value threaded through the rest of the method.
     */
    private function requireOrgId(User $user): int
    {
        $orgId = $user->organization_id;

        abort_if($orgId === null, 500, 'User management requires organization context.');

        return $orgId;
    }

    /**
     * Role assignment — two mandatory halves (D4). Resolved by explicit
     * team_id, never by string (`assignRole('admin')`), which resolves
     * through the registrar's AMBIENT team and would silently attach — or
     * create — a NULL-team role from any code path that reaches this method
     * without TenantContext having run first (a console command, a job).
     * `firstOrFail()` on an explicit team_id throws instead of doing that.
     */
    private function assignRole(User $user, int $orgId, string $roleName): void
    {
        $role = SpatieRole::where('name', $roleName)
            ->where('guard_name', 'api')
            ->where('team_id', $orgId)
            ->firstOrFail();

        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
