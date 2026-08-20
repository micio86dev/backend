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
 * No `Domain` (host-only). `SameSite=None; Secure` UNCONDITIONALLY, in every
 * environment including local dev — there is deliberately no `secure`
 * override knob (a knob is what gets flipped in production). Name has no
 * `__Host-`/`__Secure-` prefix: `__Host-` mandates `Path=/`, the opposite of
 * this design's narrow scoping; `__Secure-` stacks a second secure-origin
 * dependency onto the already-fragile local-dev story for no gain over
 * `Secure` itself.
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
            secure: true,
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_NONE,
        );
    }
}
