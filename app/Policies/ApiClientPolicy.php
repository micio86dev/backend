<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApiClient;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;

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
        // The ORG IN CONTEXT, not the actor's own column — the same
        // distinction `ApiClientController::index()` documents at length. A
        // superadmin acting as a client carries a null `organization_id`, so
        // comparing against it made this branch false for every row; they were
        // saved only by `Gate::before`, which returns true for them before any
        // policy runs. For everyone else the two are identical, so this is the
        // same cross-org refusal it always was — now expressed against the
        // context the request is actually scoped to.
        $orgId = app(TenantResolver::class)->getOrgId();

        return $user->hasRole('admin')
            && $orgId !== null
            && $client->organization_id === $orgId;
    }
}
