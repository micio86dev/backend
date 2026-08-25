<?php

declare(strict_types=1);

namespace App\DTOs\Scoring;

/**
 * The two transcript corpora a scoring call needs (evaluator-evidence-and-rigor D-1).
 *
 * They exist as ONE object, produced by ONE assembly pass over ONE in-memory
 * collection of utterances, because they must satisfy a subset invariant:
 *
 *   every candidate utterance in `validation` is also present in `prompt`.
 *
 * Two independent assembler methods would make that invariant a convention
 * enforced by tests. Built together from a single collection it is true by
 * construction and cannot drift — and the drift would be nasty: the evaluator
 * shown evidence it is then forbidden to cite, surfacing three layers later as
 * an inexplicably unverifiable excerpt.
 *
 * `prompt` — the WHOLE interview, both speakers, with the target competency's
 * segment delimited. The interviewer's questions are kept deliberately: a BEI
 * answer read without the question that provoked it is frequently unscoreable.
 *
 * `validation` — candidate-spoken text ONLY, no speaker prefixes, no delimiters.
 * Evidence about the candidate is what the candidate said; the interviewer's
 * question is not evidence that the candidate did anything, and a delimiter is
 * not evidence of anything at all.
 *
 * This replaces the previous single-string contract, under which an excerpt
 * quoting the avatar's own question passed verbatim validation.
 */
final readonly class ScoringCorpora
{
    public function __construct(
        public string $prompt,
        public string $validation,
    ) {}
}
