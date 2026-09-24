<?php

declare(strict_types=1);

/**
 * Canonical M2M ability set (C5 — M2M API Authentication).
 *
 * This file is the single source of truth for allowed API-client ability names.
 * All names are lowercase-canonical. New abilities for future slices (C6, C10, etc.)
 * MUST be added here — never hardcoded in models or controllers.
 *
 * REQ-6 / design §Abilities canonicalization
 */
return [
    'allowed' => [
        'participants:create',
        'participants:read',
        // interview-scheduling (design AD-7): deliberately narrower than
        // participants:create — a client provisioned only to create
        // participants has no standing reason to also reschedule/cancel
        // their scheduled interview.
        'participants:schedule',
        'evaluations:read',
        'progress:read',
        'projects:read',
        'sso_link:generate',

        // Public API (`/v1`) scopes — SPEC.md §3.1. Reuses this same
        // canonical set and the existing `abilities` jsonb column as the
        // scope store: `projects:read` above is shared verbatim between the
        // internal M2M surface and `/v1`, and `App\Http\Middleware\PublicApi\
        // RequireScope` checks these through the same `ApiClient::can()`
        // helper as `CheckAbility` does for the rest.
        'interviews:write',
        'interviews:read',
        'recordings:read',
        'exports:write',
        'exports:read',
        'usage:read',
        'webhooks:read',
        'webhooks:write',
    ],
];
