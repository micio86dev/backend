<?php

declare(strict_types=1);

namespace App\Services\Provider;

use App\Exceptions\ProviderException;
use App\Models\InterviewSession;

/**
 * Contract for provider session management (HeyGen LiveAvatar / Tavus).
 *
 * Implementations: HeygenProvider, TavusProvider.
 * Bound in InterviewServiceProvider based on config('interview.provider')
 * and $session->project->provider_override at request time.
 *
 * Security invariants:
 * - issue() is called OUTSIDE any DB transaction (network I/O during txn risks deadlock).
 * - issue() NEVER returns API key material; only ephemeral tokens/URLs.
 * - teardown() ALWAYS takes a typed ProviderToken — no raw-string overload.
 *   (WARNING-6: two distinct teardown contexts require different token sources.)
 *
 * REQ: ProviderSessionService interface (C7a Phase 7.7)
 */
interface ProviderSessionService
{
    /**
     * Issue a new provider session token for this competency interview.
     *
     * Called OUTSIDE any DB transaction. Returns a provider-neutral ProviderToken
     * carrying either a HeyGen session token or a Tavus conversation_url.
     *
     * @throws ProviderException on provider HTTP failure (5xx, 429).
     */
    public function issue(InterviewSession $session, QuestionContext $ctx): ProviderToken;

    /**
     * Fetch the authoritative server-side transcript for this session.
     *
     * HeyGen: returns array of utterance-like rows for REPLACE reconciliation at /end.
     * Tavus: returns [] — no reconciliation (live /utterance rows are kept as-is).
     *
     * @return array<int, array{speaker: string, text: string, ts: string}>
     */
    public function reconcileTranscript(InterviewSession $session): array;

    /**
     * Release the provider session.
     *
     * ALWAYS takes a typed ProviderToken — no raw-string overload.
     *
     * Two distinct teardown contexts (WARNING-6):
     *   - COMPENSATION (step 4d): DB failed before ref was persisted.
     *     Pass the IN-MEMORY ProviderToken returned by issue().
     *   - RESUME in_corso (step 3b): old ref IS persisted.
     *     Pass ProviderToken::fromRef($session->provider, $session->provider_session_ref).
     *
     * Best-effort: teardown failures are LOGGED but NON-FATAL (the candidate
     * needs the fresh session; orphaned old sessions are cleaned by a reaper).
     */
    public function teardown(ProviderToken $token): void;
}
