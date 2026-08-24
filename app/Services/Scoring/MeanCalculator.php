<?php

declare(strict_types=1);

namespace App\Services\Scoring;

/**
 * Recomputes competency mean score server-side (CC2/CC3).
 *
 * Input: list<int> of indicator scores in {1,2,3,4,5,-1} (widened per AD-1/D4,
 * bars-full-scale-1-5 — 2 and 4 are legal RESIDUAL levels).
 * - Assessed set: scores in {1,2,3,4,5} only (-1 excluded as unassessable sentinel).
 * - Returns float|null: null when the assessed set is empty (all -1).
 * - MUST NOT throw, return NaN, or divide by zero on an empty assessed set.
 * - Rounding: round($mean, 2, PHP_ROUND_HALF_UP) — explicit to self-document intent.
 *   PHP's default is PHP_ROUND_HALF_UP; explicit is required here as a contract signal.
 *
 * Example (CC3 golden cassette):
 *   COL {5,3,3}: (5+3+3)/3 = 3.666… → 3.67
 *   SLF {5,3,-1}: (5+3)/2 = 4.0 (denominator = assessed count = 2, not total count = 3)
 *
 * REQ: MeanCalculator (C9 D4 CC2/CC3)
 */
final class MeanCalculator
{
    /**
     * Compute the mean of assessed indicator scores.
     *
     * @param  list<int>  $indicatorScores  Scores in {1,2,3,4,5,-1}.
     * @return float|null Rounded mean (2dp, half-up), or null if no assessed indicators.
     */
    public function compute(array $indicatorScores): ?float
    {
        $assessed = array_filter($indicatorScores, static fn (int $s): bool => $s !== -1);

        if ($assessed === []) {
            return null;
        }

        $mean = array_sum($assessed) / count($assessed);

        return round($mean, 2, PHP_ROUND_HALF_UP);
    }
}
