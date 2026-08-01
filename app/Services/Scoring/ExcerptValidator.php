<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTOs\Scoring\IndicatorScoreDTO;
use App\Exceptions\Scoring\ExcerptNotVerbatimException;

/**
 * Validates that each excerpt in an IndicatorScoreDTO is a verbatim substring
 * of the assembled session transcript (after whitespace normalization).
 *
 * Whitespace normalization (CW2):
 *   - Collapse runs of \s+ (including \n, \t, multiple spaces) to a single U+0020
 *     on BOTH the excerpt AND the assembled transcript.
 *   - The ORIGINAL excerpt text is persisted, not the normalized form.
 *
 * Cross-utterance excerpts are permitted: the transcript is the full assembled string
 * (speaker-prefixed utterances joined by \n), so a cross-boundary excerpt matches
 * after normalization (\n between utterances → single space).
 *
 * Exception: score=-1 with empty excerpts → SKIP excerpt validation entirely (CC2).
 *
 * REQ: ExcerptValidator (C9 D4 CW2 — correctness-critical zone ~95%)
 */
final class ExcerptValidator
{
    /**
     * Validate all excerpts in the DTO against the assembled transcript.
     *
     * @throws ExcerptNotVerbatimException When any excerpt is not a verbatim substring.
     */
    public function validate(IndicatorScoreDTO $dto, string $assembledTranscript): void
    {
        // CC2: score=-1 with empty excerpts → no excerpt validation needed.
        if ($dto->score === -1 && $dto->excerpts === []) {
            return;
        }

        $normalizedTranscript = $this->normalizeWhitespace($assembledTranscript);

        foreach ($dto->excerpts as $excerpt) {
            $normalizedExcerpt = $this->normalizeWhitespace($excerpt);

            if (! str_contains($normalizedTranscript, $normalizedExcerpt)) {
                throw new ExcerptNotVerbatimException($excerpt, $dto->position);
            }
        }
    }

    /**
     * Collapse all whitespace runs (including \n, \t, multiple spaces) to a single space
     * and trim leading/trailing whitespace.
     */
    private function normalizeWhitespace(string $text): string
    {
        return (string) preg_replace('/\s+/', ' ', $text);
    }
}
