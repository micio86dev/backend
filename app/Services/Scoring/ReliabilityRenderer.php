<?php

declare(strict_types=1);

namespace App\Services\Scoring;

/**
 * ReliabilityRenderer — renders a stored reliability value as an integer percentage.
 *
 * Contract (D4 FIX-1 — round-before-cast invariant):
 *   (int) round($reliabilityDbValue * 100, 0, PHP_ROUND_HALF_UP)
 *
 * The round() MUST be applied BEFORE the (int) cast. Writing (int)($value * 100) instead
 * silently truncates toward zero: (int)(0.6667 * 100) = 66, not 67.
 *
 * Used at the API/webhook serialization boundary, wherever C9 renders reliability as %.
 * The stored DB value (numeric(5,4), range [0..1]) is the input.
 *
 * REQ: D4 FIX-1 Reliability rendering trap — round-before-cast (C9 PR3)
 */
class ReliabilityRenderer
{
    /**
     * Render a stored reliability value as an integer percentage.
     *
     * @param  float  $reliabilityDbValue  Value in [0..1] from competency_results.reliability.
     * @return int Integer percentage in [0..100], standard half-up rounding.
     */
    public function render(float $reliabilityDbValue): int
    {
        // CRITICAL: round() BEFORE (int) cast — see D4 FIX-1.
        return (int) round($reliabilityDbValue * 100, 0, PHP_ROUND_HALF_UP);
    }
}
