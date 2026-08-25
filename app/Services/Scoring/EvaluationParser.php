<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTOs\Scoring\IndicatorRef;
use App\DTOs\Scoring\IndicatorScoreDTO;
use App\Enums\IndicatorFailureReason;
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
 * Error paths (C13, scoring-failure-containment D7 — the parser is now TOTAL
 * over behaviors[]):
 *   - Invalid JSON / "behaviors" missing or not an array → JsonParseException.
 *   - count(behaviors) ≠ count(indicators) → IndicatorCountMismatchException.
 *   Both are ENVELOPE-level and deterministic at temperature=0 → the caller
 *   MUST NOT queue-retry either. These are now the ONLY exceptions this class
 *   can throw: an illegal per-indicator score (a non-whole-number, or a
 *   non-scalar) no longer throws — parse() emits a DTO marked
 *   asUnassessable(IndicatorFailureReason::ScoreIllegal) for that indicator
 *   instead, so one bad indicator can never discard its siblings.
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
        // D5 — strip a leading/trailing fence or conversational-prose wrapper
        // BEFORE decoding. The stripper never validates anything: json_decode()
        // remains the SOLE acceptance test. A stripper bug that returns garbage
        // produces the exact same JsonParseException as today — there is no
        // partial-acceptance path for the tolerance to leak through.
        $unwrapped = (new ResponseEnvelopeStripper)->unwrap($llmResponse)->json;

        $decoded = json_decode($unwrapped, associative: true);

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

            $coerced = $this->coerceScore($behavior['score'] ?? 0);

            $dto = new IndicatorScoreDTO(
                position: $indicator->position,
                indicatorText: $indicator->text,   // CANONICAL catalog text, NOT echoed LLM text
                // A null $coerced (illegal type) is never persisted as a real
                // score: asUnassessable() below overwrites it to -1
                // unconditionally. 0 here is a harmless, momentary placeholder.
                score: $coerced ?? 0,
                explanation: (string) ($behavior['explanation'] ?? ''),
                excerpts: $excerpts,
            );

            // D7 — every -1 score is tagged with WHY at parse time, never left
            // null: `indicator_scores_unassessable_reason_check` requires
            // unassessable_reason IS NOT NULL whenever score = -1, including
            // the PRE-EXISTING "the model itself says no evidence" case
            // (scoring-engine's "tagged model_declared" scenario), not only
            // the NEW illegal-type-coercion case introduced by this change.
            // A type-coercion failure is a PER-INDICATOR failure, not a
            // whole-competency one: this indicator alone is marked
            // unassessable; every sibling DTO in this same loop is unaffected.
            $dtos[] = match ($coerced) {
                null => $dto->asUnassessable(IndicatorFailureReason::ScoreIllegal),
                -1 => $dto->asUnassessable(IndicatorFailureReason::ModelDeclared),
                default => $dto,
            };
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
     * B2a (D7): this method NO LONGER THROWS. Returning `null` instead of
     * throwing is what makes the parser TOTAL over `behaviors[]` — the caller
     * (parse()) converts a `null` into a per-indicator
     * `asUnassessable(ScoreIllegal)` DTO rather than aborting construction of
     * every sibling DTO already built in the same loop.
     */
    private function coerceScore(mixed $raw): ?int
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

        return null;
    }
}
