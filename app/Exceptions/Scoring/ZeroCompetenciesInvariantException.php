<?php

declare(strict_types=1);

namespace App\Exceptions\Scoring;

/**
 * ZeroCompetenciesInvariantException — thrown when the completion gate detects
 * a project with zero configured competencies (data-integrity invariant violation).
 *
 * Callers must catch this, log an ERROR, and mark the participant 'errore'.
 * Division by zero in the 90% gate is prevented by this exception.
 *
 * REQ: D5 CC1 "Invariant guard: total_competencies == 0 → errore" (C9 PR3)
 */
class ZeroCompetenciesInvariantException extends \RuntimeException
{
    public function __construct(int $projectId)
    {
        parent::__construct(
            "ScoreEvaluationJob: project {$projectId} has zero configured competencies — cannot evaluate gate."
        );
    }
}
