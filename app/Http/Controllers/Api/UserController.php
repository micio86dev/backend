<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\Users\UserGuardException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\Admin\UserResource;
use App\Jobs\SendUserInvitationJob;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\RefreshTokenStore;
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
        private readonly RefreshTokenStore $refreshTokens,
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
        // Not a security control here — a just-created user has no prior
        // tokens to reject. Set so the column's meaning stays uniform across
        // every row this surface creates (generated-client-truth-and-session-safety D4).
        $user->password_changed_at = now()->startOfSecond();
        $user->save();

        $role = (string) $request->validated('role');
        $this->assignRole($user, $orgId, $role);

        // Dispatched AFTER the role is assigned — the invitation describes what
        // the recipient can do, and a job that raced the role write would
        // describe the wrong thing. Queued rather than sent inline so a mail
        // provider having a bad minute cannot make creating a user fail: the
        // account exists, and refusing to report that is the wrong failure to
        // surface.
        SendUserInvitationJob::dispatch(
            $user->id,
            $role,
            $currentUser->name,
            Organization::findOrFail($orgId)->name,
        );

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

            // NOT caught here, unlike deactivate(), and the difference is not
            // an oversight.
            //
            // This endpoint ALREADY declares a 422 — the FormRequest's
            // ValidationException `{message, errors}` — and Scramble publishes
            // one response per status. Catching would produce a second shape
            // under the same code that the spec cannot express, so the client
            // would still type a `last_admin` rejection as a field-validation
            // failure. It would be a change that looks like a fix and moves
            // nothing observable.
            //
            // The real answer is that a guard refusal is a CONFLICT with the
            // resource's current state, not a validation failure of the
            // payload — 409, not 422 — which would separate the two shapes
            // cleanly here and everywhere else. That is a deliberate contract
            // change across every guard-bearing endpoint and deserves its own
            // pass; the global render() carries it until then.
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

        // Admin-initiated password write invalidates the target's existing
        // sessions (generated-client-truth-and-session-safety D4) — the same
        // `password_changed_at`/`RejectStaleCredentials` mechanism the
        // self-service path already relies on. `store()` and `startOfSecond()`
        // for consistency: `iat` is second-precision, and the self-service
        // path already uses `startOfSecond()` for the same reason.
        if ($request->has('password')) {
            $target->password_changed_at = now()->startOfSecond();
            $target->save();

            // The OTHER half, and it is not covered by the stamp above.
            // `POST /api/auth/refresh` is public — routes/api.php gives it
            // only RequireRefreshCsrfHeader, never `auth:api` — so
            // `RejectStaleCredentials` returns early on a null user and never
            // consults `password_changed_at` at all. Without this, an admin
            // resets a compromised user's password and the attacker's stolen
            // refresh cookie keeps minting fresh access tokens.
            //
            // Both password-RESET paths already revoke
            // (ResetPasswordController, ResetUserPasswordCommand); the admin
            // surfaces did not.
            $this->refreshTokens->revokeAllForUser((int) $target->id);
        }

        return (new UserResource($target->fresh()))->response();
    }

    /**
     * POST /api/users/{id}/deactivate
     *
     * The guard refusal is RETURNED, not left to `UserGuardException::render()`.
     * Scramble infers error responses from what a controller visibly answers,
     * so a globally-rendered 422 never reached the generated client — and the
     * backoffice reads `{error}` off exactly this rejection to explain the
     * refusal. A contract the client depends on and the spec does not declare
     * is one rename away from silently degrading.
     *
     * The 422 body is `{error, message}`: `last_admin` when refusing for a
     * peer, `self_deactivation` when the caller is the last one.
     *
     * 204 No Content. Soft deactivation only — the row survives so
     * audit-relevant authorship survives (D5).
     */
    public function deactivate(int $id): Response|JsonResponse
    {
        $target = $this->reader->read($id);

        $this->authorize('deactivate', $target);

        /** @var User $currentUser */
        $currentUser = request()->user();

        try {
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
        } catch (UserGuardException $e) {
            // Refused: the write would leave no active administrator.
            return response()->json(
                ['error' => $e->errorCode(), 'message' => $e->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

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
    /**
     * The caller's organization, or a legible refusal (platform-user-management D4).
     *
     * 409 with a machine CODE, not 500. A superadmin viewing all clients
     * legitimately has no organization, and `Gate::before` grants them every
     * ability — so `authorize()` waves them straight through to here, and the
     * product puts that state one click from this endpoint. An internal server
     * error describes a fault in the system; this is a caller in the wrong
     * scope, and it was additionally paging people about it.
     *
     * A code rather than a sentence: a response body is machine-facing, this
     * API has no idea what language the reader speaks, and the backoffice
     * already renders codes through `translateServerCode`.
     *
     * BEAI's own people are managed on `/api/admin/platform-users`, which is
     * the surface this scope actually wants.
     */
    private function requireOrgId(User $user): int
    {
        $orgId = $user->organization_id;

        abort_if($orgId === null, Response::HTTP_CONFLICT, 'organization_context_required');

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
