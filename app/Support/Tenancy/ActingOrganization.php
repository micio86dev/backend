<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\Cache;

/**
 * Which client a superadmin is currently looking at
 * (RATIFIED 2026-09-02, option b).
 *
 * SERVER-SIDE, never a header the client sends. The rejected alternative —
 * `X-Organization-Id` on every request — puts a cross-tenant lever somewhere
 * any caller can set, so every endpoint has to honour it correctly and one
 * mistake becomes a cross-tenant leak. CLAUDE.md's binding constraint is "a
 * tenant must never see another tenant's data"; a client-supplied header that
 * overrides it is exactly the surface that constraint exists to avoid. Here
 * there is ONE lever, and the server owns it.
 *
 * Stored in the CACHE rather than in the JWT. A claim would need the token
 * reissued on every switch and, worse, would stay valid until it expired —
 * so revoking a superadmin's access would not revoke the view they were
 * already holding. The cache is read fresh on every request and can be cleared
 * from the server at any moment.
 *
 * The cache connection is Redis DB 1, separate from the queue on DB 0
 * (config/database.php), so a cache flush cannot take queued jobs with it and
 * losing this key costs a superadmin one click, never any data.
 */
final class ActingOrganization
{
    /**
     * No TTL, deliberately.
     *
     * A selection that silently expires mid-session would show the superadmin
     * a different tenant's data than the page they are reading, which is the
     * one failure this whole design exists to prevent. It is cleared
     * explicitly, or by clearing the cache.
     */
    private const KEY_PREFIX = 'superadmin:acting-org:';

    public function for(int $userId): ?int
    {
        $value = Cache::get(self::KEY_PREFIX.$userId);

        return is_int($value) ? $value : null;
    }

    public function set(int $userId, ?int $organizationId): void
    {
        if ($organizationId === null) {
            $this->forget($userId);

            return;
        }

        Cache::forever(self::KEY_PREFIX.$userId, $organizationId);
    }

    public function forget(int $userId): void
    {
        Cache::forget(self::KEY_PREFIX.$userId);
    }
}
