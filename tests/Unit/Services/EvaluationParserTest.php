<?php

declare(strict_types=1);

/**
 * RED — Task 14.3: EvaluationParser (C9 D4 FIX-8/FIX-9).
 *
 * Verifies:
 * (a) Response mapped by array position (index-based), NOT string-matching echoed text.
 * (b) count(behaviors) != count(indicators) → IndicatorCountMismatchException (no queue retry).
 * (c) Invalid JSON → JsonParseException.
 *
 * Refs spec: D4, FIX-8, FIX-9.
 */

use App\DTOs\Scoring\IndicatorRef;
use App\Exceptions\Scoring\IndicatorCountMismatchException;
use App\Exceptions\Scoring\JsonParseException;
use App\Services\Scoring\EvaluationParser;

// ─── Tests ───────────────────────────────────────────────────────────────────

test('(a) response mapped by array position, not string match on echoed indicator text', function (): void {
    $parser = new EvaluationParser;

    // Catalog indicators (position 0=A, 1=B, 2=C)
    $indicators = [
        new IndicatorRef(position: 0, text: 'Indicator A'),
        new IndicatorRef(position: 1, text: 'Indicator B'),
        new IndicatorRef(position: 2, text: 'Indicator C'),
    ];

    // LLM response echoes text in WRONG ORDER intentionally.
    // Position mapping must assign scores by array index, not by matching 'indicator' field.
    $llmResponse = json_encode([
        'behaviors' => [
            ['indicator' => 'WRONG TEXT FOR C (pos 0)', 'score' => 5, 'explanation' => 'Exp 0', 'excerpts' => []],
            ['indicator' => 'WRONG TEXT FOR A (pos 1)', 'score' => 3, 'explanation' => 'Exp 1', 'excerpts' => []],
            ['indicator' => 'WRONG TEXT FOR B (pos 2)', 'score' => 1, 'explanation' => 'Exp 2', 'excerpts' => []],
        ],
    ]);

    $dtos = $parser->parse($llmResponse, $indicators);

    // Must have 3 DTOs
    expect($dtos)->toHaveCount(3);

    // Position 0 (first in array) → score=5, indicator_text = catalog 'Indicator A' (not echoed text)
    expect($dtos[0]->position)->toBe(0);
    expect($dtos[0]->score)->toBe(5);
    expect($dtos[0]->indicatorText)->toBe('Indicator A');

    // Position 1 → score=3, indicator_text = 'Indicator B'
    expect($dtos[1]->position)->toBe(1);
    expect($dtos[1]->score)->toBe(3);
    expect($dtos[1]->indicatorText)->toBe('Indicator B');

    // Position 2 → score=1, indicator_text = 'Indicator C'
    expect($dtos[2]->position)->toBe(2);
    expect($dtos[2]->score)->toBe(1);
    expect($dtos[2]->indicatorText)->toBe('Indicator C');
});

test('(b) count(behaviors) != count(indicators) → IndicatorCountMismatchException', function (): void {
    $parser = new EvaluationParser;

    // 3 catalog indicators but LLM returns only 2 behaviors
    $indicators = [
        new IndicatorRef(position: 0, text: 'Indicator A'),
        new IndicatorRef(position: 1, text: 'Indicator B'),
        new IndicatorRef(position: 2, text: 'Indicator C'),
    ];

    $llmResponse = json_encode([
        'behaviors' => [
            ['indicator' => 'A', 'score' => 5, 'explanation' => 'Exp 0', 'excerpts' => []],
            ['indicator' => 'B', 'score' => 3, 'explanation' => 'Exp 1', 'excerpts' => []],
            // Missing third!
        ],
    ]);

    expect(fn () => $parser->parse($llmResponse, $indicators))
        ->toThrow(IndicatorCountMismatchException::class);
});

test('(c) invalid JSON → JsonParseException', function (): void {
    $parser = new EvaluationParser;

    $indicators = [
        new IndicatorRef(position: 0, text: 'Indicator A'),
    ];

    expect(fn () => $parser->parse('THIS IS NOT JSON {{{', $indicators))
        ->toThrow(JsonParseException::class);
});
