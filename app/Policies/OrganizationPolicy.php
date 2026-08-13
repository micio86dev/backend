<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

/**
 * OrganizationPolicy (backoffice-missing-pages D2).
 *
 * RBAC gate for the singular, self-resolving /api/organization resource.
 * Mirrors ProjectPolicy.php:30-41 verbatim: read for all roles, write
 * admin-only. There is no owner filter and no id parameter to check — the
 * resolver already guarantees the caller can only ever address their own org.
 */
class OrganizationPolicy
{
    /**
     * View the organization profile/settings — allowed for all roles.
     */
    public function view(User $user, Organization $organization): bool
    {
        return $user->hasRole('admin') || $user->hasRole('operator') || $user->hasRole('viewer');
    }

    /**
     * Update the organization profile/settings — admin only.
     */
    public function update(User $user, Organization $organization): bool
    {
        return $user->hasRole('admin');
    }
}
