<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * ProjectPolicy (C4 Project Configuration).
 *
 * RBAC gates for Project CRUD via Spatie/laravel-permission in teams mode.
 * team_id is set per-request by TenantContext middleware (C2).
 *
 * | ability             | admin | operator | viewer |
 * |---------------------|-------|----------|--------|
 * | viewAny / view      |  ✅   |   ✅     |  ✅   |
 * | create / update     |  ✅   |   ✅     |  ❌   |
 * | delete              |  ✅   |   ❌     |  ❌   |
 *
 * `delete` has its own row because it is no longer the operator's: a project
 * carries every participant, session and evaluation beneath it. It also
 * carries a STATE condition the table cannot express — never while the
 * project is `active` — which
 * lives in the controller rather than here, because `Gate::before` returns
 * true for a superadmin and short-circuits every method in this class.
 *
 * No owner_id filter: operator and admin can read/write ALL projects in the
 * org. Delete is the one exception to that sentence.
 * Controller calls $this->authorize() which resolves via Gate → this policy.
 */
class ProjectPolicy
{
    /**
     * List all projects — allowed for all roles.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('operator') || $user->hasRole('viewer');
    }

    /**
     * View a single project — allowed for all roles.
     */
    public function view(User $user, Project $project): bool
    {
        return $user->hasRole('admin') || $user->hasRole('operator') || $user->hasRole('viewer');
    }

    /**
     * Create a project — admin and operator only.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('operator');
    }

    /**
     * Update a project — admin and operator only.
     */
    public function update(User $user, Project $project): bool
    {
        return $user->hasRole('admin') || $user->hasRole('operator');
    }

    /**
     * Delete (soft-delete) a project — admin only.
     */
    public function delete(User $user, Project $project): bool
    {
        // ADMIN ONLY, narrowed from admin-or-operator.
        //
        // A project carries every participant, session, transcript and
        // evaluation beneath it. Deleting one is not the same class of act as
        // editing its settings — which an operator still may do — and the two
        // had been sharing a permission.
        //
        // Soft delete, so this hides rather than destroys; that makes the
        // narrowing safe to apply retroactively rather than a reason to skip
        // it. Superadmins pass through `Gate::before`, as everywhere.
        //
        // The ARCHIVED-only rule is deliberately NOT here. `Gate::before`
        // returns true for a superadmin and short-circuits this method
        // entirely, so a lifecycle invariant expressed in a policy is an
        // invariant every superadmin silently skips. It lives in the
        // controller, where nothing bypasses it. This method answers WHO, and
        // only WHO.
        return $user->hasRole('admin');
    }
}
