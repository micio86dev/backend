<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AvatarTemplate;
use App\Models\User;

/**
 * AvatarTemplatePolicy (C14).
 *
 * Admin only, for every ability including read.
 *
 * | ability            | admin | operator | viewer |
 * |--------------------|-------|----------|--------|
 * | viewAny / view     |  ✅   |    ❌    |   ❌   |
 * | create / update    |  ✅   |    ❌    |   ❌   |
 * | activate / delete  |  ✅   |    ❌    |   ❌   |
 *
 * Read is withheld from operator and viewer deliberately. Choosing the face and
 * voice every candidate of an organization meets is a brand decision rather
 * than a day-to-day one, and the config carries provider-side identifiers that
 * sit closer to credentials than to settings.
 *
 * Cross-org access is not checked here. AvatarTemplate is a TenantModel, so the
 * global scope means another tenant's row is never found in the first place —
 * a 404 rather than a 403, which matters: a 403 confirms the id exists and
 * turns the endpoint into an enumeration oracle.
 */
class AvatarTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, AvatarTemplate $template): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, AvatarTemplate $template): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, AvatarTemplate $template): bool
    {
        return $user->hasRole('admin');
    }

    public function activate(User $user, AvatarTemplate $template): bool
    {
        return $user->hasRole('admin');
    }
}
