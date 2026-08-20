<?php

declare(strict_types=1);

/**
 * CORS configuration (backoffice-session-refresh-hardening D1).
 *
 * Project-owned — Laravel ships NO cors.php by default, so without this file
 * the framework merges its vendor default (`allowed_origins: ['*']`,
 * `supports_credentials: false`), which the production multi-tenant API was
 * accepting from ANY origin. That default is also a hard functional blocker
 * for this change: `*` + `supports_credentials: true` is refused by every
 * browser per the Fetch spec, so an explicit allowlist is mandatory new
 * infrastructure, not a hardening nicety.
 *
 * CORS_ALLOWED_ORIGINS MUST list BOTH Nuxt origins per environment — the
 * candidate `frontend` app also calls api/* (SSO exchange, interview), so an
 * allowlist covering only the backoffice origin takes the candidate app down.
 * See docs/deploy.md.
 */
return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
        fn (string $origin): bool => $origin !== '',
    )),

    // Deliberately empty and tested (tests/Arch/Config/CorsConfigTest.php) —
    // a regex pattern (e.g. `*.up.railway.app`) is how a wildcard sneaks back
    // in and would trust every tenant of a shared platform domain.
    'allowed_origins_patterns' => [],

    // Enumerated, NEVER `*`. Per the Fetch spec, a literal `*` in
    // Access-Control-Allow-Headers is treated LITERALLY (not as a wildcard)
    // on a credentialed request — Laravel's vendor default `['*']` would
    // break every preflight the moment supports_credentials is true.
    'allowed_headers' => [
        'Accept',
        'Accept-Language',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        // backoffice-session-refresh-hardening D5: required on POST
        // /api/auth/refresh — forces a CORS preflight so an attacker's
        // origin never receives permission to send the real request.
        'X-BEAI-Refresh',
    ],

    'exposed_headers' => [],

    // Caches the preflight the custom X-BEAI-Refresh header forces on every
    // refresh call.
    'max_age' => 3600,

    // Required for the httpOnly refresh cookie to be sent/received
    // cross-origin. Incompatible with wildcard origins per the Fetch spec —
    // this is precisely why allowed_origins must be an explicit allowlist.
    'supports_credentials' => true,

];
