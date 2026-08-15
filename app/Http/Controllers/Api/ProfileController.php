<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\Admin\ProfileResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\JWTGuard;

/**
 * ProfileController (user-profile-self-service, design D1).
 *
 * GET/PATCH /api/profile, PUT /api/profile/password — the singular,
 * self-resolving profile resource. No id ever appears in the path or is
 * read from the request; the subject resolves EXCLUSIVELY from
 * `$request->user()` (the OrganizationController.php precedent, minus even
 * the id — there is no IDOR surface to test here at all).
 *
 * `UserPolicy` (admin-only, `user-management`) is deliberately untouched by
 * this controller — there is no object to authorize here, the subject IS the
 * token.
 */
class ProfileController extends Controller
{
    /**
     * GET /api/profile
     */
    public function show(): ProfileResource
    {
        /** @var User $user */
        $user = request()->user();

        // A FRESH query, not the cached auth-guard instance directly: the
        // guard's cached $user can carry a stale wasRecentlyCreated=true
        // flag from wherever it was minted, which would make JsonResource's
        // toResponse() auto-emit 201 instead of 200 on this GET.
        $fresh = $user->fresh();
        abort_if($fresh === null, 404);

        return new ProfileResource($fresh->load('organization'));
    }

    /**
     * PATCH /api/profile
     *
     * `role`, `organization_id`, `is_superadmin`, `deactivated_at` are
     * never read from the request at all — `only()` whitelists the writable
     * fields, so any of those keys in the body is silently dropped rather
     * than validated-then-rejected (design D2).
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->update($request->safe()->only(['name', 'email', 'locale']));

        $fresh = $user->fresh();
        abort_if($fresh === null, 404);

        return (new ProfileResource($fresh->load('organization')))->response();
    }

    /**
     * PUT /api/profile/password
     *
     * The acting session survives by RE-MINTING, not by exemption (design
     * D3): sets the password and `password_changed_at`, then
     * `$guard->logout()` denylists the ACTING jti (the SAME mechanism
     * AuthController::logout() uses), then `$guard->login($user)` mints a
     * brand-new token whose `iat >= password_changed_at`, returned in the
     * body — the same {access_token, token_type} shape as
     * AuthController::refresh().
     *
     * `iat` is second-precision (design D3): `startOfSecond()` here pairs
     * with RejectStaleCredentials's strict `<` comparison, so a token minted
     * in the same wall-clock second as this change is not born dead.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->password = (string) $request->validated('password');
        $user->password_changed_at = now()->startOfSecond();
        $user->save();

        /** @var JWTGuard $guard */
        $guard = auth('api');
        $guard->logout();
        $newToken = $guard->login($user);

        return response()->json([
            'access_token' => (string) $newToken,
            'token_type' => 'bearer',
        ]);
    }
}
