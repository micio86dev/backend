<?php

declare(strict_types=1);

/**
 * RED — Task 14.4: IndicatorValidator (C9 D4 — correctness-critical zone ~95%).
 *
 * Verifies:
 * (a) Score 2 rejected → InvalidIndicatorScoreException.
 * (b) Score -1 accepted as unassessable sentinel.
 * (c) Score 5 accepted.
 * (d) Score -1 with empty excerpts passes (no excerpt check when array is empty).
 *
 * Refs spec: D4 "score ∈ {1,3,5}∪{-1}".
 */

use App\DTOs\Scoring\IndicatorScoreDTO;
use App\Exceptions\Scoring\InvalidIndicatorScoreException;
use App\Services\Scoring\IndicatorValidator;

// ─── Tests ───────────────────────────────────────────────────────────────────

test('(a) score 2 rejected → InvalidIndicatorScoreException', function (): void {
    $validator = new IndicatorValidator;

    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: 2,
        explanation: 'Explanation',
        excerpts: ['Some excerpt'],
    );

    expect(fn () => $validator->validate($dto))
        ->toThrow(InvalidIndicatorScoreException::class);
});

test('(a2) score 4 rejected → InvalidIndicatorScoreException', function (): void {
    $validator = new IndicatorValidator;

    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: 4,
        explanation: 'Explanation',
        excerpts: ['Some excerpt'],
    );

    expect(fn () => $validator->validate($dto))
        ->toThrow(InvalidIndicatorScoreException::class);
});

test('(b) score -1 accepted as unassessable sentinel', function (): void {
    $validator = new IndicatorValidator;

    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: -1,
        explanation: 'No assessable evidence',
        excerpts: ['Some excerpt'],
    );

    // Must NOT throw
    expect(fn () => $validator->validate($dto))->not->toThrow(InvalidIndicatorScoreException::class);
});

test('(c) score 5 accepted', function (): void {
    $validator = new IndicatorValidator;

    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: 5,
        explanation: 'Strong evidence',
        excerpts: ['Evidence excerpt'],
    );

    expect(fn () => $validator->validate($dto))->not->toThrow(InvalidIndicatorScoreException::class);
});

test('(c2) score 1 accepted', function (): void {
    $validator = new IndicatorValidator;

    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: 1,
        explanation: 'Weak evidence',
        excerpts: ['Weak excerpt'],
    );

    expect(fn () => $validator->validate($dto))->not->toThrow(InvalidIndicatorScoreException::class);
});

test('(c3) score 3 accepted', function (): void {
    $validator = new IndicatorValidator;

    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: 3,
        explanation: 'Moderate evidence',
        excerpts: ['Moderate excerpt'],
    );

    expect(fn () => $validator->validate($dto))->not->toThrow(InvalidIndicatorScoreException::class);
});

test('(d) score -1 with empty excerpts passes — no excerpt check when array is empty', function (): void {
    $validator = new IndicatorValidator;

    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: -1,
        explanation: 'Not assessable',
        excerpts: [],
    );

    // Must NOT throw: score=-1 + empty excerpts is the CC2 unassessable pattern
    expect(fn () => $validator->validate($dto))->not->toThrow(InvalidIndicatorScoreException::class);
});
