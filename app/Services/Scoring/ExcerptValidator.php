<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTOs\Scoring\IndicatorScoreDTO;
use App\Exceptions\Scoring\ExcerptNotVerbatimException;

/**
 * Validates that each excerpt in an IndicatorScoreDTO is a verbatim substring
 * of the validation corpus (after whitespace normalization).
 *
 * Whitespace normalization (CW2):
 *   - Collapse runs of \s+ (including \n, \t, multiple spaces) to a single U+0020
 *     on BOTH the excerpt AND the corpus.
 *   - The ORIGINAL excerpt text is persisted and reported, not the normalized form.
 *
 * Cross-utterance excerpts are permitted: the corpus is an assembled string of
 * utterances joined by \n, so a cross-boundary excerpt matches after normalization.
 *
 * Elision tolerance (evaluator-evidence-and-rigor D-4):
 *   A real evaluator quotes with elisions — "Nel giro di quattro mesi... il tempo
 *   medio è crollato" — and a bare substring test rejects every one of them. An
 *   excerpt is therefore split on `...` or `…` and matched fragment by fragment.
 *
 *   THE MATCH IS AN ANCHORED FORWARD WALK, NOT A WILDCARD. Each fragment must be
 *   found at or after the byte where the previous fragment ended. This is the
 *   whole safety argument and it is why this is not `preg_quote` + `.*`:
 *     - a regex wildcard BACKTRACKS, so it would accept a quotation whose
 *       fragments the candidate spoke in the opposite order;
 *     - a forward-only cursor cannot reuse bytes, so two fragments can never
 *       overlap to manufacture a sentence that was never uttered.
 *   Tolerating an elision must never become a licence to invent evidence.
 *
 *   Empty fragments — from a leading, trailing or doubled marker — are discarded
 *   BEFORE matching. An excerpt with no surviving fragment is rejected, because
 *   `strpos($anything, '')` succeeds and a degenerate excerpt would otherwise
 *   validate against any corpus at all.
 *
 *   Zero elisions is the SAME code path: one fragment, one lookup from offset 0,
 *   byte-for-byte equivalent to the previous `str_contains`. There is deliberately
 *   no `if (hasElision)` branch, so the pre-existing behaviour cannot regress
 *   independently of the new behaviour.
 *
 *   Byte-based matching over UTF-8 is correct: UTF-8 is self-synchronizing, so a
 *   byte-level match of a valid needle in a valid haystack cannot land mid-codepoint.
 *
 * Exception: score=-1 with empty excerpts → SKIP excerpt validation entirely (CC2).
 *
 * REQ: ExcerptValidator (C9 D4 CW2, elision-tolerant D-4 — correctness-critical ~95%)
 */
final class ExcerptValidator
{
    /**
     * ASCII triple-period or U+2026 HORIZONTAL ELLIPSIS. Both, because a model
     * writing Italian emits either, and rejecting a valid quotation over a
     * codepoint choice is the same defect class this tolerance removes.
     */
    private const ELISION_PATTERN = '/\.\.\.|\x{2026}/u';

    /**
     * Validate all excerpts in the DTO against the validation corpus.
     *
     * @param  string  $validationCorpus  Candidate-spoken text only.
     *
     * @throws ExcerptNotVerbatimException When any excerpt is not a verbatim substring.
     */
    public function validate(IndicatorScoreDTO $dto, string $validationCorpus): void
    {
        // CC2: score=-1 with empty excerpts → no excerpt validation needed.
        if ($dto->score === -1 && $dto->excerpts === []) {
            return;
        }

        foreach ($dto->excerpts as $excerpt) {
            if (! $this->matchesCorpus($excerpt, $validationCorpus)) {
                // Reports the ORIGINAL excerpt: an operator must see what the
                // model actually emitted, not our normalized rewrite of it.
                throw new ExcerptNotVerbatimException($excerpt, $dto->position);
            }
        }
    }

    /**
     * Does this raw excerpt quote this raw corpus, under the SAME rules
     * `validate()` applies — whitespace normalization on both sides, then the
     * anchored forward walk over elision fragments?
     *
     * Public because a read surface needs the same question answered without
     * the throwing, DTO-shaped contract above: the session review asks whether
     * an excerpt was spoken in ONE session, given that the scoring corpus
     * deliberately spans the participant's whole interview
     * (`TranscriptAssembler`). Answering it with a second, hand-rolled
     * `str_contains` would let the read surface and the scorer disagree about
     * what "verbatim" means — an excerpt shown as unquotable that scoring
     * accepted, or the reverse. One matcher, two callers.
     *
     * NOT a substitute for `validate()`: this returns a boolean and enforces
     * nothing. `validate()` is still the only path that may admit an excerpt
     * into persistence.
     */
    public function matchesCorpus(string $excerpt, string $corpus): bool
    {
        return $this->matches(
            $this->normalizeWhitespace($excerpt),
            $this->normalizeWhitespace($corpus),
        );
    }

    /**
     * Anchored forward walk over the excerpt's fragments.
     *
     * The cursor can only ever reach exactly `strlen($corpus)` — a fragment that
     * matched necessarily fits inside the corpus — and `strpos` accepts an offset
     * equal to the subject length, so the PHP 8 out-of-range-offset ValueError is
     * unreachable here.
     */
    private function matches(string $normalizedExcerpt, string $corpus): bool
    {
        $fragments = $this->fragments($normalizedExcerpt);

        // An excerpt that is nothing but elision markers asserts nothing.
        if ($fragments === []) {
            return false;
        }

        $cursor = 0;

        foreach ($fragments as $fragment) {
            $at = strpos($corpus, $fragment, $cursor);

            if ($at === false) {
                return false;
            }

            $cursor = $at + strlen($fragment);
        }

        return true;
    }

    /**
     * Split on elision markers, trim, and drop empty fragments.
     *
     * @return list<string>
     */
    private function fragments(string $normalizedExcerpt): array
    {
        $parts = preg_split(self::ELISION_PATTERN, $normalizedExcerpt);

        if ($parts === false) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), $parts),
            static fn (string $fragment): bool => $fragment !== '',
        ));
    }

    /**
     * Collapse all whitespace runs (including \n, \t, multiple spaces) to a single space
     * and trim leading/trailing whitespace.
     */
    private function normalizeWhitespace(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
