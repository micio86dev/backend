<?php

declare(strict_types=1);

namespace App\DTOs\Audit;

/**
 * One indicator's judgment (design D2). `supportProbability` is the weakest
 * link of the three Noul questions — `min(relevance, calibration,
 * grounding)` — a conservative, explainable composition that never
 * over-claims support. `questionProbabilities` persists all three raws
 * alongside it, keyed by question id, so a later change to the composition
 * rule does not require re-paying for every run ever made.
 */
final readonly class AuditVerdict
{
    /** @param  array<string, float>  $questionProbabilities  keyed by question id */
    public function __construct(
        public int $indicatorScoreId,
        public float $supportProbability,
        public array $questionProbabilities,
    ) {}
}
