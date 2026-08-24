<?php

declare(strict_types=1);

/**
 * RED — Task 3.1 (bars-full-scale-1-5, PR3): IndicatorValidator widened domain.
 *
 * Domain widened per AD-1/D4: {1, 3, 5, -1} → {1, 2, 3, 4, 5, -1}. Scores 2 and 4
 * are now legal RESIDUAL levels (see PromptBuilder's SCORING_PROCEDURE), never
 * free LLM discretion. Illegal values remain 0, 6, any other int, or -2.
 *
 * Verifies:
 * (a) Score 2 accepted (was rejected pre-widening).
 * (a2) Score 4 accepted (was rejected pre-widening).
 * (b) Score -1 accepted as unassessable sentinel.
 * (c)/(c2)/(c3) Scores 5/1/3 accepted.
 * (d) Score -1 with empty excerpts passes (no excerpt check when array is empty).
 * (e) Score 0 rejected → InvalidIndicatorScoreException.
 * (f) Score 6 rejected → InvalidIndicatorScoreException.
 * (g) Score -2 rejected → InvalidIndicatorScoreException.
 *
 * Note on decimal scores (design.md "add new negative cases for ... decimal"):
 * `IndicatorScoreDTO::$score` is a strictly-typed `int` (declare(strict_types=1)
 * in both this file and the DTO), so a decimal such as 3.5 cannot be constructed
 * as a DTO argument at all — PHP raises a TypeError at the call site before
 * IndicatorValidator ever runs. Decimal rejection is therefore already enforced
 * structurally, one layer above this validator, by the DTO's type declaration.
 * There is no reachable code path at this layer to assert against, so no decimal
 * test case is added here (asserting a TypeError would test PHP's type system,
 * not IndicatorValidator). See apply-progress.md for the full note, incl. the
 * separate, pre-existing EvaluationParser::parse() `(int)` cast that silently
 * truncates any decimal arriving from the LLM before a DTO is ever built — a
 * gap orthogonal to this change and out of PR3's scope (IndicatorValidator /
 * PromptBuilder / prompt_version only).
 *
 * Refs spec: scoring-model "Indicator Score Domain" + scoring-engine
 * "Indicator Score Domain Validation" (bars-full-scale-1-5 delta).
 */

use App\DTOs\Scoring\IndicatorScoreDTO;
use App\Exceptions\Scoring\InvalidIndicatorScoreException;
use App\Services\Scoring\IndicatorValidator;

// ─── Tests ───────────────────────────────────────────────────────────────────

test('(a) score 2 accepted — residual level, no longer rejected', function (): void {
    $validator = new IndicatorValidator;

    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: 2,
        explanation: 'Explanation',
        excerpts: ['Some excerpt'],
    );

    expect(fn () => $validator->validate($dto))->not->toThrow(InvalidIndicatorScoreException::class);
});

test('(a2) score 4 accepted — residual level, no longer rejected', function (): void {
    $validator = new IndicatorValidator;

    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: 4,
        explanation: 'Explanation',
        excerpts: ['Some excerpt'],
    );

    expect(fn () => $validator->validate($dto))->not->toThrow(InvalidIndicatorScoreException::class);
});

test('(e) score 0 rejected → InvalidIndicatorScoreException', function (): void {
    $validator = new IndicatorValidator;

    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: 0,
        explanation: 'Explanation',
        excerpts: ['Some excerpt'],
    );

    expect(fn () => $validator->validate($dto))
        ->toThrow(InvalidIndicatorScoreException::class);
});

test('(f) score 6 rejected → InvalidIndicatorScoreException', function (): void {
    $validator = new IndicatorValidator;

    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: 6,
        explanation: 'Explanation',
        excerpts: ['Some excerpt'],
    );

    expect(fn () => $validator->validate($dto))
        ->toThrow(InvalidIndicatorScoreException::class);
});

test('(g) score -2 rejected → InvalidIndicatorScoreException', function (): void {
    $validator = new IndicatorValidator;

    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Indicator A',
        score: -2,
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
