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
        'evaluations:read',
        'progress:read',
        'projects:read',
        'sso_link:generate',
    ],
];
