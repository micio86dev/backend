<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LlmCredential;
use App\Models\User;

/**
 * LlmCredentialPolicy — SUPERADMIN only, every ability (RATIFIED 2026-09-14).
 *
 * Gated on IDENTITY, not on a role, and the difference is the whole point.
 * `hasRole('admin')` is an ORG-SCOPED grant (Spatie teams mode, `team_id =
 * organization_id`), so it answered for a tenant's own admin about a tenant's
 * own row. These rows belong to no organization any more — they are BEAI's —
 * and no org-scoped grant can describe who may touch them. `is_superadmin` is
 * the honest gate, the same one `PlatformSettingsPanel` is gated on.
 *
 * Cross-org access is no longer a question this policy can be asked: there is
 * one set of credentials and one population allowed near them.
 *
 * Fails CLOSED for everyone else, including a tenant admin who could manage
 * these yesterday. A superadmin never reaches these methods at all —
 * `Gate::before` (AppServiceProvider) answers true before any policy runs —
 * so the explicit check here is what refuses everybody who is not one.
 */
class LlmCredentialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_superadmin === true;
    }

    public function view(User $user, LlmCredential $credential): bool
    {
        return $user->is_superadmin === true;
    }

    public function create(User $user): bool
    {
        return $user->is_superadmin === true;
    }

    public function update(User $user, LlmCredential $credential): bool
    {
        return $user->is_superadmin === true;
    }

    public function delete(User $user, LlmCredential $credential): bool
    {
        return $user->is_superadmin === true;
    }
}
