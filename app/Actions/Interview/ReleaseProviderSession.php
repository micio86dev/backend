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
        if ($session->provider_session_ref === null && $session->provider_context_ref === null) {
            return;
        }

        try {
            (match ($session->provider) {
                'tavus' => app(TavusProvider::class),
                'mock' => app(MockProvider::class),
                default => app(HeygenProvider::class),
            })->teardown(new ProviderToken(
                provider: $session->provider,
                provider_session_ref: $session->provider_session_ref,
                provider_context_ref: $session->provider_context_ref,
            ));
        } catch (\Throwable) {
            // The outcome is already committed; a stale provider session must not undo it.
        }
    }
}
