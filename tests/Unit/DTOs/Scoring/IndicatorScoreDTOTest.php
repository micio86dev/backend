<?php

declare(strict_types=1);

/**
 * RED — B2a.4: IndicatorScoreDTO::asUnassessable() (C13, design.md D7).
 *
 * `asUnassessable()` returns a NEW readonly instance (the DTO is immutable):
 * - `score: -1` (AD-1's arithmetic domain is untouched — no new sentinel).
 * - `excerpts: []` — dropped deliberately: persisting a non-verbatim excerpt
 *   would store model-invented text in the field whose entire contract is
 *   "verbatim from the transcript".
 * - `explanation` PRESERVED — model prose about the indicator, already
 *   persisted for every other indicator, introduces no new data class.
 * - `unassessableReason` set to the given IndicatorFailureReason.
 * - `position` and `indicatorText` PRESERVED — identity of the indicator
 *   this DTO refers to does not change because it failed validation.
 */

use App\DTOs\Scoring\IndicatorScoreDTO;
use App\Enums\IndicatorFailureReason;

test('asUnassessable() returns a new instance with score:-1, empty excerpts, preserved explanation, and the given reason', function (): void {
    $original = new IndicatorScoreDTO(
        position: 2,
        indicatorText: 'Work effectively with others',
        score: 6, // the illegal value that triggered the conversion
        explanation: 'The candidate described a conflict resolution scenario.',
        excerpts: ['I worked with the team to resolve the disagreement'],
    );

    $unassessable = $original->asUnassessable(IndicatorFailureReason::ScoreIllegal);

    expect($unassessable)->not->toBe($original) // a NEW instance, DTO is immutable
        ->and($unassessable->score)->toBe(-1)
        ->and($unassessable->excerpts)->toBe([])
        ->and($unassessable->explanation)->toBe('The candidate described a conflict resolution scenario.')
        ->and($unassessable->unassessableReason)->toBe(IndicatorFailureReason::ScoreIllegal)
        ->and($unassessable->position)->toBe(2)
        ->and($unassessable->indicatorText)->toBe('Work effectively with others');
});

test('asUnassessable() is total over every IndicatorFailureReason case', function (IndicatorFailureReason $reason): void {
    $original = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Some indicator',
        score: -1,
        explanation: 'Some explanation.',
        excerpts: [],
    );

    expect($original->asUnassessable($reason)->unassessableReason)->toBe($reason);
})->with(array_map(static fn (IndicatorFailureReason $case): array => [$case], IndicatorFailureReason::cases()));

test('a legally-scored DTO carries a null unassessableReason by default', function (): void {
    $dto = new IndicatorScoreDTO(
        position: 0,
        indicatorText: 'Some indicator',
        score: 5,
        explanation: 'Strong evidence.',
        excerpts: ['a verbatim excerpt'],
    );

    expect($dto->unassessableReason)->toBeNull();
});
