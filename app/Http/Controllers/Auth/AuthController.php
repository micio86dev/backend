<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * AuthController (C2).
 *
 * Handles JWT login, token refresh, logout (jti denylist), and me.
 *
 * Routes (all under /api/auth):
 *   POST   /login   — public
 *   POST   /refresh — auth:api
 *   POST   /logout  — auth:api
 *   GET    /me      — auth:api
 *
 * Invariants:
 * - All protected routes use auth:api explicitly — never bare `auth`.
 * - Logout denylists the jti in the cache store (Redis in production).
 * - Logout also invalidates the Spatie permission cache (forgetCachedPermissions).
 *   Cache-invalidation mechanism: explicit call here + RoleAttached/RoleDetached listeners
 *   registered via events_enabled=true in config/permission.php.
 */
final class AuthController extends Controller
{
    /**
     * Attempt login and return a token pair.
     *
     * POST /api/auth/login
     * Public — no auth middleware.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $request->email)->first();

        if ($user === null || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $accessToken  = auth('api')->login($user);
        $refreshToken = JWTAuth::fromUser($user, [
            'token_type' => 'refresh',
            'exp'        => now()->addMinutes(config('jwt.refresh_ttl', 20160))->timestamp,
        ]);

        return $this->tokenResponse($accessToken, $refreshToken);
    }

    /**
     * Refresh the access token.
     *
     * POST /api/auth/refresh
     * Protected: auth:api
     */
    public function refresh(): JsonResponse
    {
        try {
            $newToken = auth('api')->refresh();
        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            return response()->json(['message' => 'Token could not be refreshed.'], 401);
        }

        return response()->json([
            'access_token' => $newToken,
            'token_type'   => 'bearer',
        ]);
    }

    /**
     * Logout — denylist the current token's jti + invalidate Spatie permission cache.
     *
     * POST /api/auth/logout
     * Protected: auth:api
     */
    public function logout(): JsonResponse
    {
        // Invalidate the Spatie permission cache before invalidating the JWT.
        // This ensures a subsequent check with a fresh token starts cache-clean.
        $this->forgetPermissionCache();

        auth('api')->logout();

        return response()->json(['message' => 'Successfully logged out.']);
    }

    /**
     * Return the authenticated user, their organization, and their roles.
     *
     * GET /api/auth/me
     * Protected: auth:api
     */
    public function me(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $user->load('organization');

        return response()->json([
            'user'         => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'organization' => $user->organization ? [
                'id'   => $user->organization->id,
                'name' => $user->organization->name,
            ] : null,
            'roles'        => $user->getRoleNames(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Forget the Spatie permission cache.
     *
     * Cache-invalidation mechanism (D4 / config/permission.php):
     * - events_enabled=true: RoleAttached/RoleDetached listeners call this automatically.
     * - Explicit call here ensures logout always clears the cache regardless of events.
     */
    private function forgetPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Build the standard token-pair response.
     *
     * @param  string  $accessToken
     * @param  string  $refreshToken
     */
    private function tokenResponse(string $accessToken, string $refreshToken): JsonResponse
    {
        return response()->json([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => 'bearer',
        ]);
    }
}
