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
    /**
     * STILL admin-only, and deliberately so.
     *
     * Widening this was tried and reverted. An operator does need to pick a
     * template for every project they create — `projects.avatar_template_id`
     * is NOT NULL — but this resource carries `config`, and that holds
     * provider-side identifiers (avatarId, voiceId, faceId, palId) which are
     * closer to credentials than to settings. Handing an operator the whole
     * record to let them read a name is a bad trade.
     *
     * `listOptions` below is the narrow answer: id, name and provider, which
     * is exactly what choosing one requires and nothing else.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, AvatarTemplate $template): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * Who may see the PICKER list — id, name, provider, and nothing else.
     *
     * Every role, because every project must name a template and a viewer
     * reading a project's configuration should be able to see which one it
     * names rather than a bare id.
     */
    public function listOptions(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('operator') || $user->hasRole('viewer');
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
