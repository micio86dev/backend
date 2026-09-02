<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\RefreshRotateResult;
use App\Support\Auth\RefreshRotateStatus;
use App\Support\Auth\RefreshTokenCookie;
use App\Support\Auth\RefreshTokenStore;
use App\Support\Authorization\UserAbilities;
use App\Support\ProfilePhotoUrlSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tymon\JWTAuth\JWTGuard;

/**
 * AuthController (C2, refresh flow hardened by backoffice-session-refresh-hardening).
 *
 * Handles JWT login, cookie-based refresh (rotation + reuse detection),
 * logout (jti denylist + family revoke), and me.
 *
 * Routes (all under /api/auth):
 *   POST   /login   — public
 *   POST   /refresh — public, authenticated by the httpOnly beai_refresh
 *                      cookie + RequireRefreshCsrfHeader (NEVER auth:api —
 *                      D8: it must work even after the access token expires)
 *   POST   /logout  — auth:api
 *   GET    /me      — auth:api
 *
 * Two distinct credentials (design D2/D4), never one:
 * - Access token: short-TTL jwt-auth JWT, JSON body, memory-only client-side.
 *   Carries a `fam` claim (the operator's current refresh family id) so
 *   logout can resolve which family to revoke without the cookie ever being
 *   sent to /logout (its Path is scoped to /api/auth/refresh only).
 * - Refresh credential: opaque secret in an httpOnly cookie, hashed at rest,
 *   rotated on every use, family-scoped so a detected replay revokes every
 *   descendant (App\Support\Auth\RefreshTokenStore).
 *
 * Invariants:
 * - All protected routes use auth:api explicitly — never bare `auth`.
 * - Logout denylists the jti in the cache store (Redis in production) AND
 *   revokes the refresh family named by the `fam` claim.
 * - Logout also invalidates the Spatie permission cache (forgetCachedPermissions).
 *   Cache-invalidation mechanism: explicit call here + RoleAttached/RoleDetached listeners
 *   registered via events_enabled=true in config/permission.php.
 */
final class AuthController extends Controller
{
    public function __construct(private readonly RefreshTokenStore $refreshTokens) {}

    /**
     * Attempt login and return an access token; sets the refresh cookie.
     *
     * POST /api/auth/login
     * Public — no auth middleware.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $request->email)->first();

        // A deactivated user (backoffice-missing-pages D5) gets the SAME
        // generic invalid-credentials response as a wrong password or an
        // unknown email — never a distinguishable "this account is
        // disabled" message. login.vue:47-54 is explicit that this form
        // must not become a user-enumeration oracle, and a status-specific
        // message here would make it one.
        if ($user === null || $user->isDeactivated() || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $issue = $this->refreshTokens->issue($user->id);

        $accessToken = (string) $this->jwtGuard()
            ->claims(['fam' => $issue->familyId])
            ->login($user);

        return response()->json([
            'access_token' => $accessToken,
            'token_type' => 'bearer',
        ])->withCookie(RefreshTokenCookie::build($issue));
    }

    /**
     * Refresh the access token via the httpOnly refresh cookie.
     *
     * POST /api/auth/refresh
     * PUBLIC — authenticated by cookie + RequireRefreshCsrfHeader, NEVER
     * auth:api (D8): an expired access token is exactly when this endpoint
     * must still work.
     */
    public function refresh(Request $request): JsonResponse
    {
        $cookieValue = $request->cookie((string) config('refresh_tokens.cookie.name'));
        $parsed = $this->parseCookie(is_string($cookieValue) ? $cookieValue : null);

        if ($parsed === null) {
            return $this->refreshFailure('refresh_token_invalid');
        }

        [$familyId, $secret] = $parsed;

        $result = $this->refreshTokens->rotate($familyId, $secret);

        return match ($result->status) {
            RefreshRotateStatus::Rotated => $this->refreshSuccess($result, setCookie: true),
            RefreshRotateStatus::ConcurrentDuplicate => $this->refreshSuccess($result, setCookie: false),
            RefreshRotateStatus::Invalid => $this->refreshFailure('refresh_token_invalid'),
            RefreshRotateStatus::Revoked => $this->refreshFailure('refresh_token_revoked'),
            RefreshRotateStatus::Expired => $this->refreshFailure('refresh_token_expired'),
            RefreshRotateStatus::Reused => $this->refreshFailure('refresh_token_reused'),
        };
    }

    /**
     * Logout — denylist the current token's jti, revoke its refresh family,
     * clear the refresh cookie, invalidate the Spatie permission cache.
     *
     * POST /api/auth/logout
     * Protected: auth:api
     */
    public function logout(): JsonResponse
    {
        // Invalidate the Spatie permission cache before invalidating the JWT.
        // This ensures a subsequent check with a fresh token starts cache-clean.
        $this->forgetPermissionCache();

        $familyId = $this->jwtGuard()->payload()->get('fam');

        if (is_string($familyId) && $familyId !== '') {
            $this->refreshTokens->revokeFamily($familyId);
        }

        $this->jwtGuard()->logout();

        return response()->json(['message' => 'Successfully logged out.'])
            ->withCookie(RefreshTokenCookie::clear());
    }

    /**
     * Return the authenticated user, their organization, their roles, and
     * what they may do.
     *
     * GET /api/auth/me
     * Protected: auth:api
     *
     * The `abilities` shape is spelled out for Scramble. Inferred, it comes
     * out as a bare `string` — `app(UserAbilities::class)->for()` is a
     * container call it cannot follow — and a wrong type in the spec is worse
     * than no type: both Nuxt apps generate their client from this file, so a
     * `string` there makes every `abilities.users.viewAny` read a compile
     * error in the two repositories that consume it.
     *
     * @scramble-return array{
     *     user: array{id: int, name: string, email: string, locale: string, photo_url: string|null},
     *     organization: array{id: int, name: string}|null,
     *     roles: list<string>,
     *     abilities: array{
     *         organization: array{view: bool, update: bool},
     *         apiClients: array{viewAny: bool, create: bool},
     *         users: array{viewAny: bool, create: bool},
     *         llmCredentials: array{viewAny: bool, create: bool},
     *         avatarTemplates: array{viewAny: bool, create: bool},
     *         projects: array{viewAny: bool, create: bool},
     *     },
     * }
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
                // user-profile-self-service: previously the column and
                // $fillable entry existed but /auth/me never returned it.
                'locale' => $user->locale,
                // user-avatar-image (design D4): the SAME signer ProfileResource
                // uses — /auth/me is the shell-identity contract useCurrentUser
                // caches once per page load, so this is the second (and only
                // other) caller of ProfilePhotoUrlSigner.
                'photo_url' => app(ProfilePhotoUrlSigner::class)->urlFor($user->profile_photo_path),
                // The shell renders the client switcher from this, so it has
                // to be HERE and not only on /api/profile: `/auth/me` is the
                // identity contract useCurrentUser caches once per page load,
                // and the switcher must exist before the first navigation.
                //
                // An explicit boolean, never an absent key: a UI branching on
                // `undefined` treats "not sent" and "not a superadmin" alike,
                // and would start showing the switcher the day this is renamed.
                'is_superadmin' => (bool) $user->is_superadmin,
            ],
            'organization' => $org !== null ? [
                'id' => $org->id,
                'name' => $org->name,
            ] : null,
            'roles' => $user->getRoleNames(),
            // Resolved by the POLICIES, not by reading the role names above.
            // The backoffice renders navigation and action buttons from this,
            // so a policy change removes the button on the next page load and
            // the two repositories cannot drift. It is a rendering hint and
            // never the enforcement point — every endpoint still authorizes.
            'abilities' => app(UserAbilities::class)->for($user),
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
     * Parses the raw `{family_id}.{secret}` cookie wire format (D2/D4).
     *
     * @return array{0: string, 1: string}|null
     */
    private function parseCookie(?string $cookieValue): ?array
    {
        if ($cookieValue === null || $cookieValue === '') {
            return null;
        }

        $dotPosition = strpos($cookieValue, '.');

        if ($dotPosition === false || $dotPosition === 0 || $dotPosition === strlen($cookieValue) - 1) {
            return null;
        }

        return [substr($cookieValue, 0, $dotPosition), substr($cookieValue, $dotPosition + 1)];
    }

    /**
     * Mints a fresh access token for a rotated/duplicate refresh outcome.
     *
     * Re-checks isDeactivated() explicitly: POST /api/auth/refresh runs
     * OUTSIDE auth:api (D8), so App\Http\Middleware\TenantContext's
     * deactivation kill switch never sees this request — this is the
     * equivalent enforcement point on the cookie-authenticated path.
     */
    private function refreshSuccess(RefreshRotateResult $result, bool $setCookie): JsonResponse
    {
        // RefreshRotateResult::rotated()/concurrentDuplicate() — the only two
        // named constructors this method is ever called with (see refresh()'s
        // match()) — always set $familyId. This check is real type-narrowing
        // for PHPStan, not a reachable branch: $familyId is ?string on the
        // shared value object because Invalid/Revoked/Expired/Reused leave it
        // null, and nothing here can prove that from the type system alone.
        $familyId = $result->familyId;

        if ($familyId === null) {
            return $this->refreshFailure('refresh_token_invalid');
        }

        $user = User::find($result->userId);

        if ($user === null || $user->isDeactivated()) {
            // The store already rotated/consumed the presented token (or
            // confirmed the concurrent duplicate) — revoke the family
            // outright rather than leaving a live generation behind a user
            // who can no longer authenticate.
            $this->refreshTokens->revokeFamily($familyId);

            return $this->refreshFailure('refresh_token_revoked');
        }

        $accessToken = (string) $this->jwtGuard()
            ->claims(['fam' => $familyId])
            ->login($user);

        $response = response()->json([
            'access_token' => $accessToken,
            'token_type' => 'bearer',
        ]);

        // D6: the concurrent-duplicate path issues NO Set-Cookie — doing so
        // would clobber the winner's already-rotated cookie. $setCookie is
        // only ever true for the Rotated constructor, which always carries a
        // non-null $issue — same type-narrowing reasoning as $familyId above.
        if ($setCookie) {
            $issue = $result->issue;

            if ($issue === null) {
                return $this->refreshFailure('refresh_token_invalid');
            }

            $response->withCookie(RefreshTokenCookie::build($issue));
        }

        return $response;
    }

    /**
     * Every 401 from /refresh clears the cookie (design D4).
     */
    private function refreshFailure(string $error): JsonResponse
    {
        return response()->json(['error' => $error], 401)
            ->withCookie(RefreshTokenCookie::clear());
    }
}
