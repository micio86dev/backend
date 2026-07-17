<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\JWTGuard;

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
     *
     * Token model (D4 — jwt-auth rotation):
     * - Access token: standard TTL (30 min), used for all API requests.
     * - Refresh token: re-issues a new access token via POST /api/auth/refresh.
     *   jwt-auth rotation: the SAME bearer token is posted to /refresh; the old
     *   token's jti is denylisted and a new token is returned. There is no separate
     *   long-lived opaque refresh token — the "refresh_token" field carries the
     *   same access token string returned at login.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $request->email)->first();

        if ($user === null || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $guard = $this->jwtGuard();

        // Issue the access token via the jwt guard — sets guard's current token.
        $accessToken = (string) $guard->login($user);

        // Per D4 spec: jwt-auth uses ROTATION (no separate refresh-token table).
        // The "refresh_token" field carries the same access token string — the client
        // presents it to POST /refresh to rotate it. Issuing a separate token with
        // extended TTL is deferred to C5/C6 if product requires it.
        $refreshToken = $accessToken;

        return $this->tokenResponse($accessToken, $refreshToken);
    }

    /**
     * Refresh the access token.
     *
     * POST /api/auth/refresh
     * Protected: auth:api
     *
     * Uses jwt-auth's native token ROTATION: the current bearer access token is
     * presented and a new access token is returned; the old token's jti is denylisted.
     */
    public function refresh(): JsonResponse
    {
        try {
            $newToken = $this->jwtGuard()->refresh();
        } catch (JWTException $e) {
            return response()->json(['message' => 'Token could not be refreshed.'], 401);
        }

        return response()->json([
            'access_token' => (string) $newToken,
            'token_type' => 'bearer',
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

        $this->jwtGuard()->logout();

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
        /** @var User $user */
        $user = $request->user();
        $user->load('organization');

        $org = $user->organization;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'organization' => $org !== null ? [
                'id' => $org->id,
                'name' => $org->name,
            ] : null,
            'roles' => $user->getRoleNames(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the JWTGuard for the api guard.
     *
     * Using the concrete JWTGuard type (not the StatefulGuard contract) so that
     * PHPStan can resolve JWT-specific methods (refresh, claims, invalidate, etc.)
     * which are not part of the base AuthGuard/StatefulGuard interface.
     */
    private function jwtGuard(): JWTGuard
    {
        /** @var JWTGuard $guard */
        $guard = auth('api');

        return $guard;
    }

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
     */
    private function tokenResponse(string $accessToken, string $refreshToken): JsonResponse
    {
        return response()->json([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'bearer',
        ]);
    }
}
