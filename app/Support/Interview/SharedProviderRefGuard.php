<?php

declare(strict_types=1);

namespace App\Support\Interview;

use App\Models\InterviewSession;

/**
 * Whether a provider conversation is still in use by another interview row
 * (tavus-single-session-interview, design N12).
 *
 * Several competency rows can share one provider conversation, so ending a row
 * must not end a conversation a sibling is still using. The answer never leaves
 * the server. Every caller runs inside a tenant context (the candidate request, the
 * job's `TenantContextScope::runFor()`, the reaper's per-session scope), so the ambient
 * tenant scope applies; the explicit organization predicate is a second pin, and a row
 * of another organization must never count.
 */
final class SharedProviderRefGuard
{
    /** Does ANOTHER `in_corso` row of this organization hold `$ref` for `$provider`? */
    public function hasLiveSibling(int $organizationId, string $provider, ?string $ref, int $exceptSessionId): bool
    {
        if ($ref === null || $ref === '') {
            return false;
        }

        return InterviewSession::query()
            ->where('organization_id', $organizationId)
            ->where('provider', $provider)
            ->where('provider_session_ref', $ref)
            ->where('status', 'in_corso')
            ->whereKeyNot($exceptSessionId)
            ->exists();
    }
}
