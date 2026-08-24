<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTOs\Scoring\IndicatorRef;
use App\DTOs\Scoring\IndicatorScoreDTO;
use App\Exceptions\Scoring\IndicatorCountMismatchException;
use App\Exceptions\Scoring\InvalidIndicatorScoreException;
use App\Exceptions\Scoring\JsonParseException;

/**
 * Decodes the LLM JSON response and maps behaviors to BARS indicators by ARRAY POSITION.
 *
 * Mapping rule (D4 FIX-8):
 *   The LLM echoes the indicator text for human readability only.
 *   The persisted IndicatorScore.indicator_text is the CANONICAL BARS-catalog text
 *   from the pinned framework_version_id in the project scoring locale — sourced
 *   from the $indicators collection passed to parse(), NOT from the echoed LLM text.
 *
 * Position mapping:
 *   behaviors[0] → $indicators[0] (array index, NOT by indicator field string-match).
 *
 * Error paths:
 *   - Invalid JSON → JsonParseException (catches json_decode failure).
 *   - count(behaviors) ≠ count(indicators) → IndicatorCountMismatchException.
 *   Both are deterministic at temperature=0 → the caller MUST NOT queue-retry.
 *
 * REQ: EvaluationParser (C9 D4 FIX-8/FIX-9)
 */
final class EvaluationParser
{
    /**
     * Parse the LLM JSON response into an ordered list of IndicatorScoreDTOs.
     *
     * @param  string  $llmResponse  Raw JSON string returned by the LLM.
     * @param  list<IndicatorRef>  $indicators  Catalog indicators in position order.
     * @return list<IndicatorScoreDTO>
     *
     * @throws JsonParseException When the JSON is invalid.
     * @throws IndicatorCountMismatchException When behavior count ≠ indicator count.
     */
    public function parse(string $llmResponse, array $indicators): array
    {
        $decoded = json_decode($llmResponse, associative: true);

        if ($decoded === null || json_last_error() !== JSON_ERROR_NONE) {
            throw new JsonParseException(jsonError: json_last_error_msg());
        }

        $behaviors = $decoded['behaviors'] ?? [];

        if (! is_array($behaviors)) {
            throw new JsonParseException(jsonError: '"behaviors" key is missing or not an array.');
        }

        $expectedCount = count($indicators);
        $gotCount = count($behaviors);

        if ($expectedCount !== $gotCount) {
            throw new IndicatorCountMismatchException($expectedCount, $gotCount);
        }

        $dtos = [];

        foreach ($indicators as $arrayIndex => $indicator) {
            $behavior = $behaviors[$arrayIndex];

            // Excerpts: cast to list<string>; default to empty array if missing.
            $excerpts = isset($behavior['excerpts']) && is_array($behavior['excerpts'])
                ? array_values(array_filter($behavior['excerpts'], 'is_string'))
                : [];

            $dtos[] = new IndicatorScoreDTO(
                position: $indicator->position,
                indicatorText: $indicator->text,   // CANONICAL catalog text, NOT echoed LLM text
                score: $this->coerceScore($behavior['score'] ?? 0, $indicator->position),
                explanation: (string) ($behavior['explanation'] ?? ''),
                excerpts: $excerpts,
            );
        }

        return $dtos;
    }

    /**
     * Narrow an LLM-supplied score to an int, rejecting anything that is not
     * already a whole number.
     *
     * A plain `(int)` cast used to be safe here BY ACCIDENT. Under the old
     * {1,3,5,-1} domain, `(int) 4.5` produced 4 — an illegal value — so
     * IndicatorValidator rejected the competency and the malformed response was
     * caught. Widening the domain to {1,2,3,4,5,-1} made 4 legal and removed
     * that guard: the same 4.5 would now be persisted silently as an
     * anchor-matched score the model never gave. The exposure is created by the
     * widening, so the guard belongs to it.
     *
     * Deliberately narrow. json_decode types the same logical value three ways
     * depending on how the model wrote it — `4` is int, `4.0` is float, `"4"` is
     * string — and all three honestly mean four. Rejecting them would close one
     * hole by opening another. Only a genuine fractional part, a non-numeric
     * string, or a non-scalar is a contract violation.
     *
     * An ABSENT score arrives here as the caller's `0` default and is returned
     * unchanged: absence is not a type violation, and IndicatorValidator already
     * rejects 0 with the message that names the legal set.
     *
     * @throws InvalidIndicatorScoreException When the value is not a whole number.
     */
    private function coerceScore(mixed $raw, int $position): int
    {
        if (is_int($raw)) {
            return $raw;
        }

        // is_finite() first: floor(NAN) === NAN is false so NAN falls through
        // safely, but floor(INF) === INF is TRUE, and casting INF to int is
        // undefined behaviour rather than an error.
        if (is_float($raw) && is_finite($raw) && floor($raw) === $raw) {
            return (int) $raw;
        }

        if (is_string($raw) && preg_match('/^-?\d+$/', trim($raw)) === 1) {
            return (int) trim($raw);
        }

        throw new InvalidIndicatorScoreException(
            // A non-scalar has no meaningful string form; name its TYPE instead
            // of interpolating something unreadable (or fatal, for an array).
            is_scalar($raw) && ! is_bool($raw) ? $raw : get_debug_type($raw),
            $position,
        );
    }
}
