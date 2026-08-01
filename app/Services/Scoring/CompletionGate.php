<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\Enums\EvaluationStatus;
use App\Exceptions\Scoring\ZeroCompetenciesInvariantException;

/**
 * CompletionGate — evaluates the 90% gate for an evaluation's competency results (C9 D5 CC1).
 *
 * Gate formula:
 *   valid_competencies / total_competencies >= 0.90 → EvaluationStatus::Completed
 *   else                                             → EvaluationStatus::Pending
 *
 * The >= operator is required: 9/10 = 90% → completed (boundary is inclusive).
 *
 * Invariant guard: if totalCount == 0 → throws ZeroCompetenciesInvariantException.
 *   Callers must catch this and mark the participant 'errore'.
 *
 * The gate class does NOT read from the database; the caller computes validCount and
 * totalCount (the count of project_competencies rows, fixed at project creation).
 *
 * Config-flaggable unscorable policy (gate.count_unscorable_against_total):
 *   Applied by the caller (ScoreEvaluationJob) BEFORE passing counts to this class.
 *   When true (default), unscorables are excluded from both numerator AND denominator
 *   at the caller's discretion (the gate class itself only sees the final counts).
 *
 * Note on unscorable policy: the default (count_unscorable_against_total = true) means
 *   unscorable competencies count in the totalCount denominator and are NOT valid,
 *   so they reduce the ratio. When false, the caller adjusts both validCount and
 *   totalCount to exclude unscorables. Either way, this class receives the resolved counts.
 *
 * REQ: D5 CC1 CompletionGate (C9 PR3)
 */
class CompletionGate
{
    private const GATE_THRESHOLD = 0.90;

    /**
     * Evaluate the gate and return the terminal Evaluation status.
     *
     * @param  int  $validCount  Count of CompetencyResult rows where valid = true.
     * @param  int  $totalCount  Count of project_competencies rows (fixed at project creation).
     * @return EvaluationStatus Completed or Pending.
     *
     * @throws ZeroCompetenciesInvariantException When totalCount == 0.
     */
    public function evaluate(int $validCount, int $totalCount): EvaluationStatus
    {
        if ($totalCount === 0) {
            throw new ZeroCompetenciesInvariantException(0);
        }

        $ratio = $validCount / $totalCount;

        return $ratio >= self::GATE_THRESHOLD
            ? EvaluationStatus::Completed
            : EvaluationStatus::Pending;
    }
}
