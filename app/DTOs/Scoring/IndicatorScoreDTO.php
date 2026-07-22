<?php

declare(strict_types=1);

namespace App\DTOs\Scoring;

/**
 * Data Transfer Object for a single indicator score produced by the LLM parser.
 *
 * Carries the canonical BARS-catalog indicator text (from the pinned framework_version_id
 * in the project locale), NOT the echoed text from the LLM response.
 *
 * REQ: EvaluationParser — position-based mapping (C9 D4 FIX-8)
 */
final readonly class IndicatorScoreDTO
{
    /**
     * @param  list<string>  $excerpts  Verbatim excerpts from the transcript (may be empty for score=-1).
     */
    public function __construct(
        public int $position,
        public string $indicatorText,
        public int $score,
        public string $explanation,
        public array $excerpts,
    ) {}
}
