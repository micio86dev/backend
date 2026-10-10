<?php

declare(strict_types=1);

namespace App\Actions\Interview;

use App\Models\InterviewSession;
use App\Services\Provider\HeygenProvider;
use App\Services\Provider\MockProvider;
use App\Services\Provider\ProviderToken;
use App\Services\Provider\TavusProvider;
use Illuminate\Support\Facades\Log;

/** Best-effort release of what an ended session still holds at its provider; never throws. */
final class ReleaseProviderSession
{
    public function __invoke(InterviewSession $session): void
    {
        try {
            $this->forRefs($session->provider, $session->provider_session_ref, $session->provider_context_ref);
        } finally {
            // Attempted is enough, even when the attempt threw.
            $this->markReleased($session->organization_id, $session->provider, $session->provider_session_ref);
        }
    }

    /**
     * Record that the conversation behind `$ref` was released, on every row of the organization that
     * shares it, so a late continuation is refused (tavus-single-session-interview, API-04). Callers
     * run inside a tenant context; the explicit organization predicate is a second pin. Written after
     * a release was ATTEMPTED, never when a caller skipped it: a failed teardown leaves the
     * conversation in an unknown state, and a fresh one is always safe.
     */
    public function markReleased(int $organizationId, string $provider, ?string $ref): void
    {
        if ($ref === null || $ref === '') {
            return;
        }

        InterviewSession::query()
            ->where('organization_id', $organizationId)
            ->where('provider', $provider)
            ->where('provider_session_ref', $ref)
            ->whereNull('provider_released_at')
            ->update(['provider_released_at' => now()]);
    }

    /** Release exactly these refs: a deferred caller must not release whatever the row holds by then. */
    public function forRefs(string $provider, ?string $sessionRef, ?string $contextRef): void
    {
        if ($sessionRef === null && $contextRef === null) {
            return;
        }

        try {
            (match ($provider) {
                'tavus' => app(TavusProvider::class),
                'mock' => app(MockProvider::class),
                default => app(HeygenProvider::class),
            })->teardown(new ProviderToken(
                provider: $provider,
                provider_session_ref: $sessionRef,
                provider_context_ref: $contextRef,
            ));
        } catch (\Throwable $e) {
            // The outcome is already committed; a stale provider session must not undo it.
            // Class only: a provider exception message may echo key material.
            Log::warning('interview.provider_release.failed', [
                'provider' => $provider,
                'exception' => $e::class,
            ]);
        }
    }
}
