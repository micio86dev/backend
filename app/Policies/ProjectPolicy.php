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
 * | create / update /   |  ✅   |   ✅     |  ❌   |
 * | delete              |       |          |        |
 *
 * No owner_id filter: operator and admin can read/write ALL projects in the org.
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
     * Delete (soft-delete) a project — admin and operator only.
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
        // it.
        return $user->hasRole('admin');
    }
}
