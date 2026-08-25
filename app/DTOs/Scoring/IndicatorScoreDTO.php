<?php

declare(strict_types=1);

namespace App\DTOs\Scoring;

use App\Enums\IndicatorFailureReason;

/**
 * Data Transfer Object for a single indicator score produced by the LLM parser.
 *
 * Carries the canonical BARS-catalog indicator text (from the pinned framework_version_id
 * in the project locale), NOT the echoed text from the LLM response.
 *
 * `unassessableReason` (C13, scoring-failure-containment D1/D7): null for a
 * legally-scored indicator. Set via `asUnassessable()` when the PARSER (an
 * illegal score, D7) or a per-indicator VALIDATOR (an illegal score caught by
 * `IndicatorValidator`, or a non-verbatim excerpt caught by `ExcerptValidator`)
 * rejects this indicator — never read by any scoring formula (D9, arch-tested).
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
        public ?IndicatorFailureReason $unassessableReason = null,
    ) {}

    /**
     * Return a NEW instance marked unassessable for the given reason (D7).
     *
     * `score` becomes the -1 sentinel (AD-1's arithmetic domain is untouched —
     * no new sentinel is introduced). `excerpts` is dropped deliberately:
     * persisting a non-verbatim excerpt would store model-invented text in the
     * field whose entire contract is "verbatim from the transcript". This also
     * lands the DTO on exactly the `-1` + empty-excerpts shape `ExcerptValidator`
     * already skips (CC2), so no re-validation branch is needed.
     * `explanation`, `position`, and `indicatorText` are preserved unchanged.
     */
    public function asUnassessable(IndicatorFailureReason $reason): self
    {
        return new self(
            position: $this->position,
            indicatorText: $this->indicatorText,
            score: -1,
            explanation: $this->explanation,
            excerpts: [],
            unassessableReason: $reason,
        );
    }
}
