<?php

declare(strict_types=1);

namespace App\Services\Scoring\Contracts;

/**
 * ValidityPredicate — determines whether a competency is valid based on its reliability.
 *
 * Default implementation: ThresholdValidityPredicate (V-A):
 *   isValid($reliability) ≡ $reliability >= config('scoring.validity_threshold', 0.5).
 *
 * Injectable via the container — swap via AppServiceProvider binding.
 *
 * REQ: D5 Injectable ValidityPredicate (C9 PR3)
 */
interface ValidityPredicate
{
    /**
     * Determine whether a competency with the given reliability value is valid.
     *
     * @param  float  $reliability  Value in [0..1] as computed by ReliabilityStrategy.
     * @return bool true if the competency meets the validity threshold.
     */
    public function isValid(float $reliability): bool;
}
