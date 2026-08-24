<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTOs\Scoring\IndicatorScoreDTO;
use App\Exceptions\Scoring\InvalidIndicatorScoreException;

/**
 * Enforces the BARS indicator score domain constraint.
 *
 * Legal domain: {1, 2, 3, 4, 5, -1} (widened per AD-1/D4, bars-full-scale-1-5).
 * Scores 2 and 4 are RESIDUAL levels — legal only when the LLM follows
 * PromptBuilder's SCORING_PROCEDURE tie-break (never free discretion), but this
 * validator enforces domain membership only, not procedure compliance.
 * Illegal values: 0, 6, any decimal (cast to int in the parser), or any other int.
 * Score -1 (unassessable sentinel) is ALWAYS accepted, even with empty excerpts.
 * The caller is responsible for calling ExcerptValidator separately.
 *
 * MUST NOT coerce illegal scores — MUST throw InvalidIndicatorScoreException.
 * MUST NOT trigger a queue retry on an invalid score (the caller catches and marks
 * the competency as llm_parse_error, per D4/FIX-9).
 *
 * REQ: IndicatorValidator (C9 D4 — correctness-critical zone ~95%)
 */
final class IndicatorValidator
{
    /** @var list<int> */
    private const LEGAL_SCORES = [1, 2, 3, 4, 5, -1];

    /**
     * Validate the score domain of a single indicator DTO.
     *
     * @throws InvalidIndicatorScoreException When score ∉ {1,2,3,4,5,-1}.
     */
    public function validate(IndicatorScoreDTO $dto): void
    {
        if (! in_array($dto->score, self::LEGAL_SCORES, true)) {
            throw new InvalidIndicatorScoreException($dto->score, $dto->position);
        }
    }
}
