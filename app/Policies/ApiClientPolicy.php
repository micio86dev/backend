<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApiClient;
use App\Models\User;

/**
 * ApiClientPolicy (C5 — M2M API Authentication).
 *
 * Admin-only access to credential management.
 * Operator and viewer roles are denied all credential operations.
 * Cross-org access is explicitly blocked in delete().
 *
 * REQ-8 / design §Credential management API
 *
 * | ability   | admin | operator | viewer |
 * |-----------|-------|----------|--------|
 * | viewAny   |  ✅   |   ❌     |  ❌   |
 * | create    |  ✅   |   ❌     |  ❌   |
 * | delete    |  ✅   |   ❌     |  ❌   |
 * | view/show |  N/A  |   N/A    |  N/A  | ← no show endpoint (404)
 */
class ApiClientPolicy
{
    /**
     * List clients — admin only.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * Create a new client — admin only.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * Revoke (delete) a client — admin only, same org.
     *
     * Cross-org delete is explicitly rejected even for admins.
     */
    public function delete(User $user, ApiClient $client): bool
    {
        return $user->hasRole('admin')
            && $client->organization_id === $user->organization_id;
    }
}
