<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\Services\Scoring\Contracts\ReliabilityStrategy;

/**
 * AssessableFractionReliability — default R-A reliability strategy (C9 D5).
 *
 * Formula: assessed / total
 *   - assessed = count of indicator scores in {1,2,3,4,5} (excludes -1 sentinel;
 *     widened per AD-1/D4, bars-full-scale-1-5).
 *   - total    = count($indicatorScores).
 *
 * Returns 0.0 when the assessed set is empty (all -1 or empty input).
 * MUST NOT throw, return NaN, or divide by zero on an empty set.
 *
 * REQ: D5 AssessableFractionReliability R-A (C9 PR3)
 */
class AssessableFractionReliability implements ReliabilityStrategy
{
    /**
     * Compute the reliability as assessed / total.
     *
     * @param  list<int>  $indicatorScores  Values in {1,2,3,4,5,-1}.
     * @return float [0..1]; 0.0 when assessed set is empty.
     */
    public function compute(array $indicatorScores): float
    {
        $total = count($indicatorScores);

        if ($total === 0) {
            return 0.0;
        }

        $assessed = count(array_filter($indicatorScores, static fn (int $s): bool => $s !== -1));

        if ($assessed === 0) {
            return 0.0;
        }

        return $assessed / $total;
    }
}
