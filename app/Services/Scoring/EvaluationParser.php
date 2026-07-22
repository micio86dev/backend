<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTOs\Scoring\IndicatorRef;
use App\DTOs\Scoring\IndicatorScoreDTO;
use App\Exceptions\Scoring\IndicatorCountMismatchException;
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
                score: (int) ($behavior['score'] ?? 0),
                explanation: (string) ($behavior['explanation'] ?? ''),
                excerpts: $excerpts,
            );
        }

        return $dtos;
    }
}
