<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LlmCredential;
use App\Models\User;

/**
 * LlmCredentialPolicy (pluggable-conversation-llm PR P2, design D9).
 *
 * Admin only, every ability — mirrors AvatarTemplatePolicy exactly. A
 * credential is closer to a secret than a setting, and cross-org access is
 * not checked here: LlmCredential is a TenantModel, so another tenant's row
 * is never found in the first place — 404, never 403.
 */
class LlmCredentialPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function view(User $user, LlmCredential $credential): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, LlmCredential $credential): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, LlmCredential $credential): bool
    {
        return $user->hasRole('admin');
    }
}
