<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\JWTGuard;

/**
 * RejectStaleCredentials middleware (user-profile-self-service, design D3).
 *
 * MUST run AFTER auth:api (relies on the authenticated user already being
 * loaded by the JWT guard) — appended to the `api` group AFTER TenantContext
 * (bootstrap/app.php). Deliberately its OWN middleware, not folded into
 * TenantContext: three route groups call withoutMiddleware(TenantContext::class)
 * (M2M, SSO exchange, candidate routes), and piggybacking here would let a
 * future tenancy exemption silently exempt credential revocation too.
 *
 * Mechanism: there is no per-user token registry to enumerate and denylist
 * individual jtis against (design D3 — verified: config/jwt.php's blacklist
 * denylists exactly the ONE presented jti, at logout time). Instead, every
 * token's `iat` claim is compared against the user's `password_changed_at`
 * timestamp. `iat` is second-precision, so `password_changed_at` MUST be
 * stored via `->startOfSecond()` (ProfileController) and compared with a
 * STRICT `<` here — otherwise a token minted in the same wall-clock second as
 * the change would be born dead. Accepted residual (design D3): a FOREIGN
 * token minted in that same second survives.
 *
 * `password_changed_at IS NULL` means "never changed" — passes unconditionally.
 *
 * REQ: Password Change Revokes Other Sessions, Not The Acting One
 * (openspec/changes/user-profile-self-service/specs/user-self-service/spec.md)
 */
final class RejectStaleCredentials
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        /** @var User $user */
        if ($user->password_changed_at === null) {
            return $next($request);
        }

        /** @var JWTGuard $guard */
        $guard = auth('api');

        try {
            $iat = $guard->payload()->get('iat');
        } catch (\Throwable) {
            // No parseable token payload (e.g. a non-JWT guard reached this
            // point somehow) — fail open here, auth:api already gated access.
            return $next($request);
        }

        if (! is_numeric($iat)) {
            return $next($request);
        }

        if ((int) $iat < $user->password_changed_at->getTimestamp()) {
            return response()->json(['error' => 'credentials_changed'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
