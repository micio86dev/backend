<?php

declare(strict_types=1);

/**
 * RED — Task 14.5: ExcerptValidator (C9 D4 CW2 — correctness-critical zone ~95%).
 *
 * Verifies:
 * (a) Verbatim excerpt accepted.
 * (b) Non-verbatim excerpt rejected → ExcerptNotVerbatimException.
 * (c) Multi-space whitespace normalization.
 * (d) Newline/tab whitespace normalization.
 * (e) Cross-utterance excerpt accepted.
 *
 * Refs spec: D4 CW2 "whitespace-normalized verbatim substring".
 */

use App\DTOs\Scoring\IndicatorScoreDTO;
use App\Exceptions\Scoring\ExcerptNotVerbatimException;
use App\Services\Scoring\ExcerptValidator;

// ─── Tests ───────────────────────────────────────────────────────────────────

test('(a) verbatim excerpt accepted', function (): void {
    $validator = new ExcerptValidator;
    $transcript = 'Candidate: I worked on a critical project. Avatar: Tell me more.';

    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: 5,
        explanation: 'Explanation',
        excerpts: ['I worked on a critical project'],
    );

    expect(fn () => $validator->validate($dto, $transcript))
        ->not->toThrow(ExcerptNotVerbatimException::class);
});

test('(b) non-verbatim excerpt rejected → ExcerptNotVerbatimException', function (): void {
    $validator = new ExcerptValidator;
    $transcript = 'Candidate: I worked on a project.';

    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: 5,
        explanation: 'Explanation',
        excerpts: ['I worked on a completely different thing'],
    );

    expect(fn () => $validator->validate($dto, $transcript))
        ->toThrow(ExcerptNotVerbatimException::class);
});

test('(c) multi-space whitespace normalization — excerpt with double space matches single-space transcript', function (): void {
    $validator = new ExcerptValidator;

    // Transcript has single spaces
    $transcript = 'Candidate: I worked on a critical project.';

    // Excerpt has double spaces (LLM produced them) — after normalization it should match
    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: 5,
        explanation: 'Explanation',
        excerpts: ['I worked  on a critical project'],  // double space between "worked" and "on"
    );

    expect(fn () => $validator->validate($dto, $transcript))
        ->not->toThrow(ExcerptNotVerbatimException::class);
});

test('(d) newline and tab whitespace normalization', function (): void {
    $validator = new ExcerptValidator;

    // Transcript has embedded \n (e.g. the assembled transcript itself may have them)
    $transcript = "Candidate: I worked\ton a critical\nproject today.";

    // After normalization both become single spaces
    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: 5,
        explanation: 'Explanation',
        excerpts: ['I worked on a critical project today'],
    );

    expect(fn () => $validator->validate($dto, $transcript))
        ->not->toThrow(ExcerptNotVerbatimException::class);
});

test('(e) cross-utterance excerpt accepted — excerpt spans utterance boundary in assembled transcript', function (): void {
    $validator = new ExcerptValidator;

    // Assembled transcript: two utterances joined by \n
    $transcript = "Candidate: I worked on a project.\nAvatar: That sounds interesting.";

    // Cross-utterance excerpt: spans across the \n boundary
    // After whitespace normalization \n → space, making it a contiguous substring
    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: 5,
        explanation: 'Explanation',
        excerpts: ['I worked on a project. Avatar: That sounds interesting'],
    );

    expect(fn () => $validator->validate($dto, $transcript))
        ->not->toThrow(ExcerptNotVerbatimException::class);
});

test('(f) score -1 with empty excerpts skips excerpt validation entirely', function (): void {
    $validator = new ExcerptValidator;
    $transcript = 'Candidate: Some content.';

    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: -1,
        explanation: 'Not assessable',
        excerpts: [],
    );

    // CC2: empty excerpts + score=-1 → no excerpt check → must NOT throw
    expect(fn () => $validator->validate($dto, $transcript))
        ->not->toThrow(ExcerptNotVerbatimException::class);
});

// ─── evaluator-evidence-and-rigor: PR 2 — elision tolerance (D-4) ────────────

/**
 * The reference BEI evaluator quotes with elisions naturally — "Nel giro di
 * quattro mesi... il tempo medio è crollato". A bare str_contains rejects every
 * one of them. Tolerating that shape must NOT become a licence to assemble a
 * sentence the candidate never said, which is what these cases pin down.
 */
function elisionDto(string $excerpt): IndicatorScoreDTO
{
    return new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: 4,
        explanation: 'Explanation',
        excerpts: [$excerpt],
    );
}

const ELISION_TRANSCRIPT = 'candidate: Nel giro di quattro mesi abbiamo rifatto la pipeline e il tempo medio è crollato a otto minuti.';

test('(f) task 5.1 — ASCII ellipsis, fragments in order → accepted', function (): void {
    expect(fn () => (new ExcerptValidator)->validate(
        elisionDto('Nel giro di quattro mesi... il tempo medio è crollato'),
        ELISION_TRANSCRIPT,
    ))->not->toThrow(ExcerptNotVerbatimException::class);
});

test('(g) task 5.2 — U+2026 ellipsis character → accepted', function (): void {
    expect(fn () => (new ExcerptValidator)->validate(
        elisionDto('Nel giro di quattro mesi… il tempo medio è crollato'),
        ELISION_TRANSCRIPT,
    ))->not->toThrow(ExcerptNotVerbatimException::class);
});

test('(h) task 5.3 — fragments present but OUT OF ORDER → rejected', function (): void {
    // Both fragments exist in the transcript. The excerpt asserts an order the
    // candidate did not speak in, so it is a fabricated quotation.
    expect(fn () => (new ExcerptValidator)->validate(
        elisionDto('il tempo medio è crollato... Nel giro di quattro mesi'),
        ELISION_TRANSCRIPT,
    ))->toThrow(ExcerptNotVerbatimException::class);
});

test('(i) task 5.4 — fragments that would have to OVERLAP → rejected', function (): void {
    // "abbiamo rifatto la" and "rifatto la pipeline" both occur, but the second
    // can only match by reusing bytes the first already consumed.
    expect(fn () => (new ExcerptValidator)->validate(
        elisionDto('abbiamo rifatto la...rifatto la pipeline'),
        ELISION_TRANSCRIPT,
    ))->toThrow(ExcerptNotVerbatimException::class);
});

test('(j) task 5.5 — an elision does NOT license invented text', function (): void {
    expect(fn () => (new ExcerptValidator)->validate(
        elisionDto('abbiamo rifatto la pipeline... e ho licenziato il team'),
        ELISION_TRANSCRIPT,
    ))->toThrow(ExcerptNotVerbatimException::class);
});

test('(k) task 6.1 — leading elision is discarded, accepted on the remainder', function (): void {
    expect(fn () => (new ExcerptValidator)->validate(
        elisionDto('...il tempo medio è crollato'),
        ELISION_TRANSCRIPT,
    ))->not->toThrow(ExcerptNotVerbatimException::class);
});

test('(l) task 6.2 — trailing elision is discarded, accepted on the remainder', function (): void {
    expect(fn () => (new ExcerptValidator)->validate(
        elisionDto('il tempo medio è crollato...'),
        ELISION_TRANSCRIPT,
    ))->not->toThrow(ExcerptNotVerbatimException::class);
});

test('(m) task 6.3 — adjacent markers produce an empty fragment that is discarded, not matched', function (): void {
    expect(fn () => (new ExcerptValidator)->validate(
        elisionDto('quattro mesi......il tempo medio'),
        ELISION_TRANSCRIPT,
    ))->not->toThrow(ExcerptNotVerbatimException::class);
});

test('(n) task 6.4 — an excerpt of ONLY elision markers is rejected, never a zero-length accept', function (): void {
    // This is the hole the design names explicitly: str_contains($any, '') is
    // true, so a degenerate excerpt must be rejected before it reaches matching.
    expect(fn () => (new ExcerptValidator)->validate(
        elisionDto('...'),
        ELISION_TRANSCRIPT,
    ))->toThrow(ExcerptNotVerbatimException::class);

    expect(fn () => (new ExcerptValidator)->validate(
        elisionDto('… …'),
        ELISION_TRANSCRIPT,
    ))->toThrow(ExcerptNotVerbatimException::class);
});

test('(o) task 7.2 — the exception carries the ORIGINAL excerpt text and the indicator position', function (): void {
    $dto = new IndicatorScoreDTO(
        position: 7,
        indicatorText: 'Indicator A',
        score: 4,
        explanation: 'Explanation',
        excerpts: ['mai   detto... da nessuno'],
    );

    try {
        (new ExcerptValidator)->validate($dto, ELISION_TRANSCRIPT);
        $this->fail('Expected ExcerptNotVerbatimException');
    } catch (ExcerptNotVerbatimException $e) {
        // Original, NOT whitespace-normalised — the operator must see what the
        // model actually emitted.
        expect($e->getMessage())->toContain('mai   detto... da nessuno')
            ->and($e->getMessage())->toContain('7');
    }
});

test('(p) task 7.3 — score -1 with empty excerpts still short-circuits before any matching', function (): void {
    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: -1,
        explanation: 'No assessable evidence',
        excerpts: [],
    );

    expect(fn () => (new ExcerptValidator)->validate($dto, ELISION_TRANSCRIPT))
        ->not->toThrow(ExcerptNotVerbatimException::class);
});

test('(q) task 5.6 — a literal ellipsis the candidate actually spoke still matches', function (): void {
    // The transcript itself contains "...". Splitting the excerpt on the marker
    // must still resolve, because each fragment is looked up independently.
    $transcript = 'candidate: Ho esitato... poi ho deciso di procedere.';

    expect(fn () => (new ExcerptValidator)->validate(
        elisionDto('Ho esitato... poi ho deciso'),
        $transcript,
    ))->not->toThrow(ExcerptNotVerbatimException::class);
});
