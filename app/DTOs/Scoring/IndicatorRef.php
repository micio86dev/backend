<?php

declare(strict_types=1);

namespace App\DTOs\Scoring;

/**
 * Lightweight reference to a BARS catalog indicator.
 *
 * Used by EvaluationParser to map LLM behaviors to canonical indicator data
 * by array position. Carries only the fields needed for position-based mapping:
 *   - position: the zero-based array index (also the DB position column).
 *   - text: the canonical indicator text in the project's scoring locale.
 *
 * REQ: EvaluationParser indicator mapping (C9 D4 FIX-8)
 */
final readonly class IndicatorRef
{
    public function __construct(
        public int $position,
        public string $text,
    ) {}
}
