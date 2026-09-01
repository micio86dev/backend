<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Cookie contract (D4):
 *
 *   Set-Cookie: beai_refresh={family_id}.{secret};
 *               HttpOnly; Secure; SameSite=None;
 *               Path=/api/auth/refresh;
 *               Max-Age={absolute_expires_at - now}
 *
 * No `Domain` (host-only). Name has no
 * `__Host-`/`__Secure-` prefix: `__Host-` mandates `Path=/`, the opposite of
 * this design's narrow scoping; `__Secure-` stacks a second secure-origin
 * dependency onto the already-fragile local-dev story for no gain over
 * `Secure` itself.
 *
 * SECURE / SAMESITE ARE DERIVED FROM THE CONNECTION, NEVER CONFIGURED
 * ------------------------------------------------------------------
 * This used to be `SameSite=None; Secure` unconditionally, with a docblock
 * explaining that there is deliberately no `secure` override knob "because a
 * knob is what gets flipped in production". That reasoning is right and is
 * kept: there is still no knob.
 *
 * But the consequence was that local development did not work at all. `Secure`
 * means the browser sends the cookie only over HTTPS, and local dev is
 * `http://localhost`, so the refresh cookie was never sent — every page reload
 * landed on the login screen, and the only way to stay signed in was to never
 * refresh.
 *
 * The pair is now derived from the REQUEST, which nobody sets:
 *
 *   HTTPS  → `Secure; SameSite=None`   — production, byte-identical to before
 *   plain  → `SameSite=Lax`, no Secure — local dev only
 *
 * `SameSite=None` REQUIRES `Secure`; a browser drops a `SameSite=None` cookie
 * without it, so the two must move together. `Lax` is sufficient on localhost
 * because SameSite compares registrable domains and ignores the port — the
 * backoffice on :3001 and the API on :8000 are the same site.
 *
 * IT FAILS CLOSED. The downgrade needs the request to be plain HTTP **and**
 * the application to be running in the `local` environment. A production
 * deployment accidentally served over HTTP keeps `Secure` and simply stops
 * working, which is the direction a security control should fail in.
 */
final class RefreshTokenCookie
{
    public static function build(RefreshTokenIssue $issue): Cookie
    {
        $maxAge = max($issue->absoluteExpiresAt - now()->getTimestamp(), 0);

        return self::cookie($issue->cookieValue(), $maxAge);
    }

    /**
     * Clears the cookie. `Set-Cookie` carries its own `Path` attribute, so
     * this correctly deletes the narrow-scoped cookie without ever touching
     * `Path=/`.
     */
    public static function clear(): Cookie
    {
        return self::cookie('', 0);
    }

    private static function cookie(string $value, int $maxAgeSeconds): Cookie
    {
        return Cookie::create(
            name: (string) config('refresh_tokens.cookie.name'),
            value: $value,
            expire: $maxAgeSeconds > 0 ? now()->addSeconds($maxAgeSeconds)->getTimestamp() : 0,
            path: (string) config('refresh_tokens.cookie.path'),
            domain: null,
            secure: self::requiresSecure(),
            httpOnly: true,
            raw: false,
            // Moves WITH `secure`: a browser drops a `SameSite=None` cookie
            // that is not also `Secure`, so pairing `None` with a non-secure
            // cookie would silently produce no cookie at all.
            sameSite: self::requiresSecure() ? Cookie::SAMESITE_NONE : Cookie::SAMESITE_LAX,
        );
    }

    /**
     * True unless BOTH conditions for a local-development downgrade hold.
     *
     * Two conditions rather than one, so the control fails closed: a
     * production deployment accidentally served over plain HTTP keeps `Secure`
     * and stops working, rather than quietly shipping a cookie any network
     * hop can read.
     *
     * Reads the request rather than any setting, so there is nothing for
     * anyone to misconfigure — which is what the original "no knob" rule was
     * protecting, and it still holds.
     */
    private static function requiresSecure(): bool
    {
        if (! app()->environment('local')) {
            return true;
        }

        $request = request();

        return $request === null || $request->isSecure();
    }
}
