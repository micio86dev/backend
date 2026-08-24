<?php

declare(strict_types=1);

namespace App\Services\Scoring\Contracts;

/**
 * ReliabilityStrategy — computes a reliability score for a set of indicator scores.
 *
 * Default implementation: AssessableFractionReliability (R-A):
 *   assessed / total, where assessed = count of scores in {1,2,3,4,5}
 *   (widened per AD-1/D4, bars-full-scale-1-5).
 *   Returns 0.0 when assessed set is empty.
 *
 * Injectable via the container — swap via AppServiceProvider binding.
 *
 * REQ: D5 Injectable ReliabilityStrategy (C9 PR3)
 */
interface ReliabilityStrategy
{
    /**
     * Compute the reliability for a competency's indicator scores.
     *
     * @param  list<int>  $indicatorScores  Values in {1,2,3,4,5,-1}. -1 = unassessable sentinel.
     * @return float Reliability in [0..1]. Returns 0.0 when the assessed set is empty.
     */
    public function compute(array $indicatorScores): float;
}
