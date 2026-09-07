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

    /**
     * The selected client, or null for the whole estate.
     *
     * The read accepts a DIGIT STRING as well as an int, and that is not
     * defensive padding — it is the store's contract. Laravel's RedisStore
     * writes finite numerics unserialized
     * (`shouldBeStoredWithoutSerialization()`) and reads them back untouched
     * (`unserialize()` short-circuits on `is_numeric()`), so an id put in as
     * `7` comes out of phpredis as the string `'7'`. The original `is_int()`
     * guard rejected exactly that, and since `CACHE_STORE=array` in
     * phpunit.xml hands back the original int, the whole suite passed while
     * production — on `CACHE_STORE=redis` — resolved every read to null: the
     * PUT returned 200 and the selection was gone on the next request.
     *
     * `ctype_digit` rather than `is_numeric`: an organization id is a whole
     * positive number, and `is_numeric` would also accept `'1.9'` and quietly
     * truncate it to a different tenant's id. Anything else reads as "no
     * client selected", which is the safe answer — it narrows nothing and
     * shows the superadmin the all-clients view they can act from.
     */
    public function for(int $userId): ?int
    {
        $value = Cache::get(self::KEY_PREFIX.$userId);

        $id = match (true) {
            is_int($value) => $value,
            is_string($value) && ctype_digit($value) => (int) $value,
            default => null,
        };

        // POSITIVE, not merely whole. `ctype_digit('0')` is true, so a `0` in
        // the key resolved to organization 0 — an id the sequence never issues.
        // It fails closed (TenantContext scopes to it with bypass off, and every
        // read comes back empty), but "scoped to an organization that cannot
        // exist" and "no client selected" are different states, and only the
        // second one is one the superadmin can act from.
        return $id !== null && $id > 0 ? $id : null;
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
