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
use App\Enums\IndicatorFailureReason;
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

// ─── Fence / prose tolerance (A2.3, design.md D5) ────────────────────────────
//
// EvaluationParser::parse() wires ResponseEnvelopeStripper before
// json_decode(). json_decode() remains the SOLE acceptance test — the
// stripper never validates anything.

test('a markdown-fenced body parses successfully via parse()', function (): void {
    $parser = new EvaluationParser;
    $indicators = [new IndicatorRef(position: 0, text: 'Indicator A')];

    $response = "```json\n".'{"behaviors":[{"indicator":"Indicator A","score":5,"explanation":"E","excerpts":[]}]}'."\n```";

    expect($parser->parse($response, $indicators)[0]->score)->toBe(5);
});

test('leading conversational prose is stripped before parse()', function (): void {
    $parser = new EvaluationParser;
    $indicators = [new IndicatorRef(position: 0, text: 'Indicator A')];

    $response = 'Here is my evaluation:'."\n"
        .'{"behaviors":[{"indicator":"Indicator A","score":3,"explanation":"E","excerpts":[]}]}';

    expect($parser->parse($response, $indicators)[0]->score)->toBe(3);
});

test('a genuinely malformed body still hard-fails after the tolerance pass', function (): void {
    // A stray trailing comma INSIDE the JSON structure itself — not a
    // fence/prose wrapper. Already starts with `{` and ends with `}`, so the
    // stripper is a no-op; json_decode() still fails on the malformed inner.
    $parser = new EvaluationParser;
    $indicators = [new IndicatorRef(position: 0, text: 'Indicator A')];

    $response = '{"behaviors":[{"indicator":"Indicator A","score":5,"explanation":"E","excerpts":[],}]}';

    expect(fn () => $parser->parse($response, $indicators))
        ->toThrow(JsonParseException::class);
});

// ─── Score type coercion (bars-full-scale-1-5) ───────────────────────────────
//
// `(int) 4.5` is 4. Under the old {1,3,5,-1} domain that truncation was caught
// by accident: 4 was illegal, so a fractional score always ended as
// llm_parse_error. Widening the domain to {1,2,3,4,5,-1} removed that
// accidental guard — 4 is now legal, so a truncated 4.5 would be persisted in
// silence as a real, anchor-matched score it never was.
//
// The rule is narrow on purpose. json_decode types the SAME logical value three
// ways depending on how the model wrote it — `4` is int, `4.0` is float, `"4"`
// is string — and all three legitimately mean four. Only a genuine fractional
// part is a contract violation, and only that is rejected.
//
// B2a (design.md D7): a type-coercion failure is now a PER-INDICATOR failure,
// not a whole-competency one — EvaluationParser no longer THROWS for an
// illegal score; it emits a DTO marked unassessable(ScoreIllegal) instead.
// After this change, the only exceptions EvaluationParser can throw are
// envelope-level (JsonParseException, IndicatorCountMismatchException) — see
// the throw-set test at the bottom of this section.

test('a fractional score is REJECTED as ScoreIllegal, never truncated to a legal neighbour, and never throws', function (): void {
    $parser = new EvaluationParser;
    $indicators = [new IndicatorRef(position: 0, text: 'Indicator A')];

    $response = json_encode([
        'behaviors' => [
            ['indicator' => 'Indicator A', 'score' => 4.5, 'explanation' => 'E', 'excerpts' => ['some excerpt']],
        ],
    ], JSON_THROW_ON_ERROR);

    $dto = $parser->parse($response, $indicators)[0];

    expect($dto->score)->toBe(-1)
        ->and($dto->unassessableReason)->toBe(IndicatorFailureReason::ScoreIllegal)
        ->and($dto->excerpts)->toBe([], 'excerpts are dropped for an unassessable DTO (D7)')
        ->and($dto->explanation)->toBe('E');
});

test('an integer-valued float is accepted — 4.0 means four', function (): void {
    // json_decode types `4.0` as float. Rejecting it would fail a response that
    // is correct in every way that matters.
    $parser = new EvaluationParser;
    $indicators = [new IndicatorRef(position: 0, text: 'Indicator A')];

    $response = '{"behaviors":[{"indicator":"Indicator A","score":4.0,"explanation":"E","excerpts":[]}]}';

    expect($parser->parse($response, $indicators)[0]->score)->toBe(4);
});

test('a numeric string score is accepted — "4" means four', function (): void {
    // A quoted number is a plausible LLM formatting choice, not a contract
    // violation. Rejecting it would close one hole by opening another.
    $parser = new EvaluationParser;
    $indicators = [new IndicatorRef(position: 0, text: 'Indicator A')];

    $response = '{"behaviors":[{"indicator":"Indicator A","score":"4","explanation":"E","excerpts":[]}]}';

    expect($parser->parse($response, $indicators)[0]->score)->toBe(4);
});

test('a fractional STRING score is rejected as ScoreIllegal too, never throws', function (): void {
    $parser = new EvaluationParser;
    $indicators = [new IndicatorRef(position: 0, text: 'Indicator A')];

    $response = '{"behaviors":[{"indicator":"Indicator A","score":"4.5","explanation":"E","excerpts":[]}]}';

    $dto = $parser->parse($response, $indicators)[0];

    expect($dto->score)->toBe(-1)
        ->and($dto->unassessableReason)->toBe(IndicatorFailureReason::ScoreIllegal);
});

test('a non-scalar score is rejected as ScoreIllegal without a type error', function (): void {
    // `(int) []` is 0 with a warning, and 0 is illegal — so this happened to
    // fail before. It must fail on PURPOSE, marked with a reason that names
    // what happened, not by accident of PHP's loose casting.
    $parser = new EvaluationParser;
    $indicators = [new IndicatorRef(position: 0, text: 'Indicator A')];

    $response = '{"behaviors":[{"indicator":"Indicator A","score":{"value":4},"explanation":"E","excerpts":[]}]}';

    $dto = $parser->parse($response, $indicators)[0];

    expect($dto->score)->toBe(-1)
        ->and($dto->unassessableReason)->toBe(IndicatorFailureReason::ScoreIllegal);
});

test('EvaluationParser never throws for an illegal per-indicator score — the throw set is now envelope-only', function (): void {
    $parser = new EvaluationParser;
    $indicators = [
        new IndicatorRef(position: 0, text: 'Indicator A'),
        new IndicatorRef(position: 1, text: 'Indicator B'),
    ];

    // Position 0 is illegal (out-of-range whole number is IndicatorValidator's
    // job downstream, but a non-whole-number/non-scalar is THIS class's job);
    // position 1 is legal. Both indicators must come back as DTOs — parse()
    // must not throw and must not lose the sibling.
    $response = '{"behaviors":['
        .'{"indicator":"Indicator A","score":"not-a-number","explanation":"E0","excerpts":[]},'
        .'{"indicator":"Indicator B","score":5,"explanation":"E1","excerpts":["ok"]}'
        .']}';

    $dtos = $parser->parse($response, $indicators);

    expect($dtos)->toHaveCount(2)
        ->and($dtos[0]->score)->toBe(-1)
        ->and($dtos[0]->unassessableReason)->toBe(IndicatorFailureReason::ScoreIllegal)
        ->and($dtos[1]->score)->toBe(5)
        ->and($dtos[1]->unassessableReason)->toBeNull();
});

test('a legitimate model-declared -1 score is tagged ModelDeclared (D7, scoring-engine "tagged model_declared")', function (): void {
    // Required for indicator_scores_unassessable_reason_check: EVERY -1 score
    // must carry a non-null unassessable_reason at persist time, including the
    // pre-existing "the model itself says no evidence" case — not only the
    // new ScoreIllegal case.
    $parser = new EvaluationParser;
    $indicators = [new IndicatorRef(position: 0, text: 'Indicator A')];

    $response = '{"behaviors":[{"indicator":"Indicator A","score":-1,"explanation":"No episode given.","excerpts":[]}]}';

    $dto = $parser->parse($response, $indicators)[0];

    expect($dto->score)->toBe(-1)
        ->and($dto->unassessableReason)->toBe(IndicatorFailureReason::ModelDeclared);
});

test('an absent score stays 0 and is left for IndicatorValidator to reject', function (): void {
    // Unchanged behaviour, pinned: absence is not a type violation, and the
    // validator already rejects 0 with the message that names the legal set.
    $parser = new EvaluationParser;
    $indicators = [new IndicatorRef(position: 0, text: 'Indicator A')];

    $response = '{"behaviors":[{"indicator":"Indicator A","explanation":"E","excerpts":[]}]}';

    expect($parser->parse($response, $indicators)[0]->score)->toBe(0);
});
