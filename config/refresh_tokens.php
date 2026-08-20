<?php

declare(strict_types=1);

/**
 * Refresh-token family configuration (backoffice-session-refresh-hardening
 * D2/D4/D6).
 *
 * No database migration — every value here governs Redis-only state built by
 * App\Support\Auth\RefreshTokenStore, reusing the atomic-consume convention
 * already established by App\Support\Jwt\CandidateTokenFactory::consumeJti()
 * (Cache::add() = Redis SET NX EX).
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Absolute ceiling
    |--------------------------------------------------------------------------
    |
    | ABSOLUTE, never sliding (A4): stamped once at login as
    | `absolute_expires_at` and copied unchanged through every rotation. Every
    | TTL written by RefreshTokenStore is computed as `absolute_expires_at -
    | now`, NEVER as a re-assertion of this constant — that subtraction is the
    | entire difference between absolute and sliding expiry (D2).
    |
    */
    'absolute_ttl_minutes' => (int) env('REFRESH_TOKEN_ABSOLUTE_TTL_MINUTES', 20160), // 14 days

    /*
    |--------------------------------------------------------------------------
    | Concurrent-refresh grace window (D6)
    |--------------------------------------------------------------------------
    |
    | Two tabs presenting the SAME cookie within this window are treated as a
    | concurrent duplicate (fresh access token, no rotation, no Set-Cookie),
    | not a replay. Outside the window, or on a generation mismatch, the
    | family is revoked. Tradeoff (D6, A7): a thief replaying within this
    | window gets one access token and trips no alarm — they would have won
    | that race under any design. Config-driven so it can be tuned without a
    | code change.
    |
    */
    'concurrency_grace_seconds' => (int) env('REFRESH_CONCURRENCY_GRACE_SECONDS', 10),

    /*
    |--------------------------------------------------------------------------
    | Cookie (D4)
    |--------------------------------------------------------------------------
    |
    | Path is intentionally NOT `/` — narrow-scoped to the one route that
    | consumes it. No `Domain` (host-only). `SameSite=None; Secure`
    | unconditionally in every environment, including local dev — there is
    | deliberately no `secure` override knob here, because a knob is what
    | gets flipped in production (D4).
    |
    */
    'cookie' => [
        'name' => 'beai_refresh',
        'path' => '/api/auth/refresh',
    ],

];
