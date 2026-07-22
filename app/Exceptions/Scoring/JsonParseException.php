<?php

declare(strict_types=1);

namespace App\Exceptions\Scoring;

/**
 * Thrown when the LLM response cannot be decoded as valid JSON.
 *
 * Triggers unscorable_reason = 'llm_parse_error' (FIX-9).
 * The caller catches this and marks the competency unscorable.
 * MUST NOT trigger a queue retry (same as IndicatorCountMismatchException).
 *
 * REQ: EvaluationParser — persistent invalid JSON → llm_parse_error (C9 D4 FIX-9)
 */
final class JsonParseException extends \RuntimeException
{
    public function __construct(string $competencyCode = '', string $jsonError = '')
    {
        $ctx = $competencyCode !== '' ? " for competency [{$competencyCode}]" : '';
        $err = $jsonError !== '' ? ": {$jsonError}" : '';
        parent::__construct("JSON parse error{$ctx}{$err}.");
    }
}
