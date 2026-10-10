<?php

declare(strict_types=1);

namespace App\Support\Interview;

use App\Models\InterviewSession;
use App\Models\InterviewSessionLivePeriod;
use Illuminate\Support\Carbon;

/**
 * How old a provider conversation is against the ceiling it was created with
 * (tavus-single-session-interview, design A10/N7).
 *
 * Age is the SPAN from the earliest live period that held the ref, not a sum of stretches: the
 * provider's clock runs while the candidate is away. The ceiling is not computed here:
 * `SessionLiveClock::resolveMaxSeconds` owns it (template cap first, platform cap second), so the
 * number the clock caps a period with and the number a conversation is refused at cannot drift.
 * Callers run inside a tenant context; the period model is tenant-scoped.
 */
final class ProviderRefLifetime
{
    public function __construct(private readonly SessionLiveClock $clock) {}

    public function ageSeconds(string $ref): int
    {
        $earliest = InterviewSessionLivePeriod::query()->where('provider_session_ref', $ref)->min('started_at');

        return $earliest === null ? 0 : max(0, (int) Carbon::parse($earliest)->diffInSeconds(now(), true));
    }

    public function ceilingSeconds(InterviewSession $session): int
    {
        return $this->clock->resolveMaxSeconds($session);
    }

    /** Would a conversation this old have too little room left to finish a competency? */
    public function isNearCeiling(InterviewSession $session, string $ref): bool
    {
        return $this->ageSeconds($ref) + (int) config('conversation.ceiling_headroom_seconds') >= $this->ceilingSeconds($session);
    }
}
