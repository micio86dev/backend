<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * UserPolicy (backoffice-missing-pages D4).
 *
 * User management is a privilege-escalation surface: every ability is
 * admin-only, on every verb. There is no viewer/operator read access — unlike
 * ProjectPolicy/ParticipantPolicy/EvaluationPolicy, which are all-roles-read.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, User $target): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, User $target): bool
    {
        return $user->hasRole('admin');
    }

    public function deactivate(User $user, User $target): bool
    {
        return $user->hasRole('admin');
    }

    public function activate(User $user, User $target): bool
    {
        return $user->hasRole('admin');
    }
}
