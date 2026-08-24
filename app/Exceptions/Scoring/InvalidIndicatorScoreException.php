<?php

declare(strict_types=1);

namespace App\Exceptions\Scoring;

/**
 * Thrown when an indicator score is outside the legal domain {1,2,3,4,5,-1}.
 *
 * Values 0, 6, decimals, or any other out-of-set value trigger this.
 * The caller marks the competency as llm_parse_error.
 * MUST NOT coerce the score; MUST NOT trigger a queue retry.
 *
 * REQ: IndicatorValidator — score ∈ {1,2,3,4,5,-1} (C9 D4, widened bars-full-scale-1-5)
 */
final class InvalidIndicatorScoreException extends \RuntimeException
{
    /**
     * @param  int|float|string  $score  The offending value AS RECEIVED. Widened
     *                                   beyond `int` so EvaluationParser can name
     *                                   what actually arrived — reporting a
     *                                   fractional 4.5 as "4" would describe the
     *                                   truncation instead of the defect, and the
     *                                   message exists to be read by whoever has
     *                                   to work out why a competency died.
     */
    public function __construct(int|float|string $score, int $position = -1)
    {
        $ctx = $position >= 0 ? " at position {$position}" : '';
        parent::__construct(
            "Invalid indicator score [{$score}]{$ctx}: must be one of {1, 2, 3, 4, 5, -1}."
        );
    }
}
