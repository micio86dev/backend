<?php

declare(strict_types=1);

namespace App\Actions\Interview;

use App\Models\InterviewSession;
use App\Services\Provider\HeygenProvider;
use App\Services\Provider\MockProvider;
use App\Services\Provider\ProviderToken;
use App\Services\Provider\TavusProvider;

/** Best-effort release of what an ended session still holds at its provider; never throws. */
final class ReleaseProviderSession
{
    public function __invoke(InterviewSession $session): void
    {
        $this->forRefs($session->provider, $session->provider_session_ref, $session->provider_context_ref);
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
        } catch (\Throwable) {
            // The outcome is already committed; a stale provider session must not undo it.
        }
    }
}
