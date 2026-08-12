<?php

declare(strict_types=1);

namespace App\Support\Users;

use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single sanctioned path to obtain a User for the admin user-management
 * surface (backoffice-missing-pages D4).
 *
 * Mirrors AdminParticipantReader (C11 D1) / M2m/ParticipantController.php:110-111's
 * explicit-resolve discipline: `User` is a plain Model (no TenantScoped global
 * scope), so a bare `User::findOrFail()` anywhere on this surface would return
 * another org's row with a 200.
 *
 * Fixed query, always both predicates together:
 *   1. Org filter — cross-org id → ModelNotFoundException → 404 (not yours;
 *      never 403, which would confirm the row exists — an existence oracle).
 *   2. `is_superadmin = false` — a platform superadmin who happens to carry an
 *      organization_id is invisible here: not demotable, not deactivatable,
 *      not listable. Enforces the BEAI proposal invariant "a platform
 *      superadmin is never manageable through a tenant's user-management
 *      surface" at the query layer rather than in a serializer.
 */
final class UserAdminReader
{
    public function __construct(
        private readonly TenantResolver $resolver,
    ) {}

    public function read(int $userId): User
    {
        return $this->baseQuery()->findOrFail($userId);
    }

    /**
     * The sanctioned path to list users for the admin index endpoint.
     * Applies the same org filter + is_superadmin exclusion the single-read
     * path applies, before the controller adds any further request filters.
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
        return User::where('organization_id', $this->resolver->getOrgId())
            ->where('is_superadmin', false);
    }
}
