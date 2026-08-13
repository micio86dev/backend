<?php

declare(strict_types=1);

namespace App\Services\Proctoring;

use App\Models\InterviewSession;

/**
 * Estimates what an avatar session cost, from its stored duration (C11, D5).
 *
 * DERIVED, never persisted. Rates change; a figure stored under an old rate
 * becomes a number nobody can reproduce or explain. Computing it at read time
 * keeps the calculation inspectable and keeps the session table free of a
 * column that would age badly.
 *
 * Always an ESTIMATE. Neither HeyGen nor Tavus exposes a per-session billed
 * amount through an API, so callers must label it as such.
 *
 * The LLM side is NOT summed in here: token cost comes from `ai_requests` via
 * `AiRequestCostEstimator`, and the two are different vendors on different
 * meters. One total would be a number with no owner.
 *
 * REQ: Session cost is derived from stored timings, not recorded
 */
final class SessionCostEstimator
{
    /**
     * @return array{provider: string, minutes: float, usd: float}|null
     *                                                                  Null when the session has not finished: a partial duration would
     *                                                                  produce a confident number for something still running.
     */
    public function estimate(InterviewSession $session): ?array
    {
        if ($session->started_at === null || $session->ended_at === null) {
            return null;
        }

        $seconds = $session->ended_at->getTimestamp() - $session->started_at->getTimestamp();

        if ($seconds <= 0) {
            return null;
        }

        $minutes = $seconds / 60;
        $provider = $session->provider;

        $usd = match ($provider) {
            'heygen' => $minutes
                * (float) config('interview.rates.heygen.credits_per_minute')
                * (float) config('interview.rates.heygen.usd_per_credit'),
            'tavus' => $minutes * (float) config('interview.rates.tavus.usd_per_minute'),
            // An unrecognised provider yields no estimate rather than zero:
            // zero reads as "this was free", which is a different claim.
            default => null,
        };

        if ($usd === null) {
            return null;
        }

        return [
            'provider' => $provider,
            'minutes' => round($minutes, 2),
            'usd' => round($usd, 4),
        ];
    }
}
