<?php

declare(strict_types=1);

namespace App\Exceptions\Scoring;

/**
 * Thrown when the LLM returns a different number of behaviors than
 * the number of catalog indicators injected.
 *
 * At temperature=0, this is a deterministic structural failure: retrying
 * the queue would reproduce the same wrong count. Therefore the caller
 * MUST NOT trigger a queue retry — mark as llm_parse_error immediately.
 *
 * REQ: EvaluationParser count mismatch → llm_parse_error (C9 D4 FIX-8/FIX-9)
 */
final class IndicatorCountMismatchException extends \RuntimeException
{
    public function __construct(int $expected, int $got, string $competencyCode = '')
    {
        $ctx = $competencyCode !== '' ? " for competency [{$competencyCode}]" : '';
        parent::__construct(
            "Indicator count mismatch{$ctx}: expected {$expected}, got {$got}."
        );
    }
}
