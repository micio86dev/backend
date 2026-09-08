<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlatformUserRequest;
use App\Http\Requests\UpdatePlatformUserRequest;
use App\Http\Resources\Admin\PlatformUserResource;
use App\Jobs\SendUserInvitationJob;
use App\Models\User;
use App\Support\Auth\RefreshTokenStore;
use App\Support\Users\PlatformUserGuards;
use App\Support\Users\PlatformUserReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * BEAI's own people (platform-user-management D1).
 *
 * The all-clients counterpart of `UserController`: a superadmin with no client
 * selected is not managing an organization, they are managing the platform
 * team. Selecting a client switches the backoffice back to `/api/users`, which
 * this change does not touch.
 *
 * Routes (auth:api + TenantContext, superadmin-only):
 *   GET    /admin/platform-users
 *   POST   /admin/platform-users
 *   PATCH  /admin/platform-users/{id}
 *   POST   /admin/platform-users/{id}/deactivate
 *   POST   /admin/platform-users/{id}/activate
 *
 * Every user is reached exclusively through `PlatformUserReader` — never a
 * bare `User::` call — so an organization's row cannot be touched here
 * whatever id is supplied. Deactivation routes through `PlatformUserGuards`,
 * the only place the last-superadmin invariant is enforced.
 *
 * Deliberately absent: a `role` field (D2 — `is_superadmin` is the only
 * platform identity, so there is nothing to pick between) and DELETE (the
 * reason `UserController` records: a DELETE that does not delete would lie
 * about what it does).
 */
class PlatformUserController extends Controller
{
    public function __construct(
        private readonly PlatformUserReader $reader,
        private readonly PlatformUserGuards $guards,
        private readonly RefreshTokenStore $refreshTokens,
    ) {}

    /**
     * Refuse anyone who is not a superadmin.
     *
     * Asserted here rather than in a policy or a FormRequest, following
     * `SuperadminController`'s doctrine verbatim: there is no model to
     * authorize against — the subject is the CALLER, not a row. A policy would
     * also be useless, since `Gate::before` answers every ability `true` for a
     * superadmin and would never be consulted for anyone else's benefit.
     */
    private function assertSuperadmin(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->is_superadmin === true, Response::HTTP_FORBIDDEN);

        return $user;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->assertSuperadmin($request);

        return PlatformUserResource::collection(
            $this->reader->listQuery()->orderBy('name')->get()
        );
    }

    /**
     * `organization_id` and `is_superadmin` are never read from the request —
     * they are decided here, exactly as `UserController::store()` decides them
     * for an organization's people. A field that is never read cannot be
     * crafted.
     */
    public function store(StorePlatformUserRequest $request): JsonResponse
    {
        $currentUser = $this->assertSuperadmin($request);

        $user = new User($request->safe()->only(['name', 'email', 'password']));
        $user->organization_id = null;
        $user->is_superadmin = true;
        // Set so the column's meaning stays uniform across every row any
        // surface creates — not a security control on a user who has no prior
        // tokens to reject.
        $user->password_changed_at = now()->startOfSecond();
        $user->save();

        // No role assignment, and no Spatie team: a platform user belongs to
        // no organization, so there is no team_id for a role to hang on.
        //
        // Queued rather than sent inline, for the same reason as the org
        // surface: the account exists, and a mail provider having a bad minute
        // must not make creating it fail.
        SendUserInvitationJob::dispatch(
            $user->id,
            'superadmin',
            $currentUser->name,
            // No organization to name. The notification renders
            // `intro_platform` on null rather than substituting BEAI, which
            // would read ":inviter added you to BEAI on BEAI."
            null,
        );

        return (new PlatformUserResource($user->fresh()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdatePlatformUserRequest $request, int $id): JsonResponse
    {
        $this->assertSuperadmin($request);

        $target = $this->reader->read($id);
        $target->update($request->safe()->only(['name', 'email', 'password']));

        // An admin-set password MUST end the sessions it replaces, and on this
        // surface that matters more than anywhere else: `Gate::before` answers
        // every ability true for these users, so a live token on a stolen
        // laptop is unlimited access to every client on the platform.
        //
        // TWO mechanisms, because one does not cover the other.
        // `password_changed_at` drives `RejectStaleCredentials`, which passes
        // UNCONDITIONALLY while that column is null — the sibling
        // `UserController::update()` stamps it for exactly this reason.
        // Refresh tokens are not covered by it at all: `POST /api/auth/refresh`
        // is public and never runs that middleware (RefreshTokenStore says so
        // in its own docblock), so a stolen refresh cookie would keep minting
        // fresh access tokens. Both password-reset paths already revoke; the
        // admin surfaces did not.
        if ($request->has('password')) {
            $target->password_changed_at = now()->startOfSecond();
            $target->save();

            $this->refreshTokens->revokeAllForUser((int) $target->id);
        }

        return (new PlatformUserResource($target->fresh()))->response();
    }

    /**
     * The guarded verb. Reaching zero active superadmins is unrecoverable from
     * inside the product, so the count and the write share one transaction and
     * one row lock — see PlatformUserGuards.
     */
    public function deactivate(Request $request, int $id): Response
    {
        $currentUser = $this->assertSuperadmin($request);
        $target = $this->reader->read($id);

        $this->guards->ensureSuperadminSurvivesThenMutate(
            actor: $currentUser,
            target: $target,
            mutate: function () use ($target): void {
                $target->deactivated_at = now();
                $target->save();
            },
        );

        return response()->noContent();
    }

    /**
     * Never guarded: activating only ever ADDS a survivor, so there is no
     * invariant for it to break.
     */
    public function activate(Request $request, int $id): Response
    {
        $this->assertSuperadmin($request);

        $target = $this->reader->read($id);
        $target->deactivated_at = null;
        $target->save();

        return response()->noContent();
    }
}
