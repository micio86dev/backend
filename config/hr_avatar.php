<?php

declare(strict_types=1);

/**
 * HR-Avatar integration (the standalone avatar conversation engine).
 *
 * The integration is AVAILABLE only when all three values are set (see
 * App\Services\Provider\HrAvatarConnection::isAvailable()). Unset, `hr_avatar`
 * can be neither written as a template provider nor selected for a session, and
 * no request ever leaves for an HR-Avatar host.
 *
 *   base_url        — HR-Avatar origin, an absolute http(s) URL (browser-safe).
 *   secret_key      — server-to-server credential. NEVER returned to a client,
 *                     logged, or exported.
 *   publishable_key — browser-safe key the SDK connects with.
 */
return [
    'base_url' => env('HR_AVATAR_BASE_URL'),
    'secret_key' => env('HR_AVATAR_SECRET_KEY'),
    'publishable_key' => env('HR_AVATAR_PUBLISHABLE_KEY'),
];
