<?php

declare(strict_types=1);

namespace App\Support\Users;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The single sanctioned path to obtain a PLATFORM user — one of BEAI's own
 * people (platform-user-management D1).
 *
 * The mirror image of {@see UserAdminReader}, and deliberately a separate
 * class rather than a flag on that one. That reader carries its invariant in
 * its WHERE clause —
 *
 *   `is_superadmin = false` — a platform superadmin ... is invisible here:
 *   not demotable, not deactivatable, not listable.
 *
 * — precisely because a conditional is what gets forgotten. Teaching it to
 * sometimes include superadmins would delete that invariant and replace it
 * with a branch to audit. Here the predicate is simply inverted, the two
 * populations never meet, and an id belonging to the wrong one raises
 * ModelNotFoundException on both.
 *
 * BOTH predicates, always together:
 *   1. `organization_id IS NULL` — a platform user belongs to no client.
 *   2. `is_superadmin = true` — and is one of BEAI's own.
 *
 * Either alone lets a row through that the other would refuse: a superadmin
 * still carrying an organization_id, or an orphaned org user whose
 * organization column happens to be null.
 *
 * There is no tenant filter and there is nothing to add: these rows belong to
 * no tenant, and the surface that uses this reader is superadmin-only.
 */
final class PlatformUserReader
{
    /**
     * @throws ModelNotFoundException<User>
     */
    public function read(int $userId): User
    {
        return $this->baseQuery()->findOrFail($userId);
    }

    /**
     * Deactivated platform users ARE included. Hiding them would make
     * reactivating one impossible — the same reason the org surface lists its
     * deactivated users.
     *
     * @return Builder<User>
     */
    public function listQuery(): Builder
    {
        return $this->baseQuery();
    }

    /**
     * @return Builder<User>
     */
    private function baseQuery(): Builder
    {
        return User::query()
            ->whereNull('organization_id')
            ->where('is_superadmin', true);
    }
}
