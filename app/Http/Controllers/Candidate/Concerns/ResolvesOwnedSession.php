<?php

declare(strict_types=1);

namespace App\Http\Controllers\Candidate\Concerns;

use App\Models\InterviewSession;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * ResolvesOwnedSession — shared session-ownership resolver (C7a).
 *
 * Provides a single, consistent security invariant for ALL session-scoped endpoints
 * (/end, /utterance, /integrity, /snapshot). Each of those 4 controllers uses this
 * trait to resolve a session that the authenticated candidate owns.
 *
 * Isolation layers applied by this resolver:
 *   1. TenantScoped global scope — automatically filters by organization_id from the
 *      TenantResolver (set by TenantContextCandidate middleware). This ensures sessions
 *      from other orgs are invisible at the query level (not just 404 at the model level).
 *   2. participant_id constraint — WHERE participant_id = auth()->id() — ensures a
 *      candidate cannot access another participant's session within the same org.
 *   3. findOrFail — returns ModelNotFoundException (→ 404) for any nonexistent,
 *      non-owned, or cross-tenant session. 404 is deliberately used (not 403)
 *      to avoid leaking session existence information to cross-participants.
 *
 * WARNING-4 (design): this method MUST NOT be private. It is used by 4 different
 * controllers (InterviewController, UtteranceController, IntegrityController,
 * SnapshotController). Private methods cannot be shared via trait inclusion across
 * separate controller classes.
 *
 * Usage in every session-scoped endpoint:
 *   $session = $this->resolveOwnedSession($request->integer('session_id'));
 *   // ← MUST be called FIRST, before any DB mutation.
 *
 * REQ: Session ownership resolver (C7a)
 */
trait ResolvesOwnedSession
{
    /**
     * Resolve an InterviewSession that belongs to the authenticated candidate.
     *
     * Applies two filters:
     *   - TenantScoped: WHERE organization_id = <current tenant>  (global scope, automatic)
     *   - Participant:  WHERE participant_id = auth()->id()        (explicit constraint)
     *
     * Returns 404 via ModelNotFoundException for any non-owned, cross-org, or nonexistent session.
     *
     * @throws ModelNotFoundException
     */
    protected function resolveOwnedSession(int $id): InterviewSession
    {
        return InterviewSession::where('participant_id', auth()->id())->findOrFail($id);
    }
}
