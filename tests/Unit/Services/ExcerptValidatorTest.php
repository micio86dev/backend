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
