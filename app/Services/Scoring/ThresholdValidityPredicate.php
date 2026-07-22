<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\Services\Scoring\Contracts\ValidityPredicate;

/**
 * ThresholdValidityPredicate — default V-A validity predicate (C9 D5).
 *
 * A competency is valid if:
 *   reliability >= config('scoring.validity_threshold', 0.5)
 *
 * The threshold T is read from config at call time — injectable via the scoring config
 * without code changes (SCORING_VALIDITY_THRESHOLD env var).
 * Client must ratify T before IT production scoring go-live.
 *
 * REQ: D5 ThresholdValidityPredicate V-A (C9 PR3)
 */
class ThresholdValidityPredicate implements ValidityPredicate
{
    /**
     * Determine whether the competency reliability meets the validity threshold.
     *
     * @param  float  $reliability  Value in [0..1] as computed by ReliabilityStrategy.
     * @return bool true if reliability >= T; false otherwise.
     */
    public function isValid(float $reliability): bool
    {
        $threshold = (float) config('scoring.validity_threshold', 0.5);

        return $reliability >= $threshold;
    }
}
