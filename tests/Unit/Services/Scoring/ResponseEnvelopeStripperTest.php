<?php

declare(strict_types=1);

/**
 * RED — A2.1: ResponseEnvelopeStripper (C13, design.md D5).
 *
 * One predicate, two narrow rules, applied to the TRIMMED input:
 * 1. Fence — starts with ``` (optional language tag on the same line) AND a
 *    closing ``` exists → take what is between them. wasFenced = true.
 * 2. Prose — otherwise, extract from the first `{` to the last `}` — but
 *    ONLY if the discarded leading/trailing runs contain none of `{`, `}`, `"`.
 *
 * The stripper NEVER validates anything — `json_decode()` remains the sole
 * acceptance test. A stripper that returns garbage produces a
 * JsonParseException identical to today's; there is no partial-acceptance
 * path.
 */

use App\Services\Scoring\ResponseEnvelopeStripper;

test('a markdown-fenced JSON body is unwrapped and marked wasFenced', function (): void {
    $stripper = new ResponseEnvelopeStripper;

    $raw = "```json\n{\"behaviors\": []}\n```";
    $result = $stripper->unwrap($raw);

    expect($result->json)->toBe('{"behaviors": []}')
        ->and($result->wasFenced)->toBeTrue();
});

test('a fence with no language tag is unwrapped the same way', function (): void {
    $stripper = new ResponseEnvelopeStripper;

    $raw = "```\n{\"behaviors\": []}\n```";
    $result = $stripper->unwrap($raw);

    expect($result->json)->toBe('{"behaviors": []}')
        ->and($result->wasFenced)->toBeTrue();
});

test('leading conversational prose is stripped, wasFenced is false', function (): void {
    $stripper = new ResponseEnvelopeStripper;

    $raw = 'Here is the evaluation:'."\n".'{"behaviors": []}';
    $result = $stripper->unwrap($raw);

    expect($result->json)->toBe('{"behaviors": []}')
        ->and($result->wasFenced)->toBeFalse();
});

test('trailing conversational prose is stripped', function (): void {
    $stripper = new ResponseEnvelopeStripper;

    $raw = '{"behaviors": []}'."\n".'I hope this evaluation is helpful!';
    $result = $stripper->unwrap($raw);

    expect($result->json)->toBe('{"behaviors": []}')
        ->and($result->wasFenced)->toBeFalse();
});

test('clean JSON with no wrapper is returned unchanged (no-op)', function (): void {
    $stripper = new ResponseEnvelopeStripper;

    $raw = '{"behaviors": []}';
    $result = $stripper->unwrap($raw);

    expect($result->json)->toBe('{"behaviors": []}')
        ->and($result->wasFenced)->toBeFalse();
});

test('refuses to strip when the discarded leading run contains a quote', function (): void {
    $stripper = new ResponseEnvelopeStripper;

    // The leading run (everything before the FIRST `{`) contains a `"` —
    // never safe to discard. (A leading run cannot itself contain a `{` by
    // construction: strpos() would have found THAT one as the first brace.)
    $raw = 'He said "hello" then '.'{"behaviors": []}';
    $result = $stripper->unwrap($raw);

    // Strips nothing — the raw (trimmed) body is returned untouched.
    expect($result->json)->toBe($raw)
        ->and($result->wasFenced)->toBeFalse();
});

test('refuses to strip when the discarded trailing run contains a quote', function (): void {
    $stripper = new ResponseEnvelopeStripper;

    $raw = '{"behaviors": []}'.' the model said "done"';
    $result = $stripper->unwrap($raw);

    expect($result->json)->toBe($raw)
        ->and($result->wasFenced)->toBeFalse();
});

test('a fence followed by trailing prose containing a brace is refused by the fence rule', function (): void {
    $stripper = new ResponseEnvelopeStripper;

    $raw = "```json\n{\"behaviors\": []}\n```\nNote: the model considered {other approaches} too.";
    $result = $stripper->unwrap($raw);

    // Either the fence rule refuses (unsafe trailing run after the closing
    // fence) or the prose-rule fallback extracts a garbage span — both are
    // acceptable per D5 ("the worst case of a stripper bug is the failure we
    // already have — never a silent mis-parse"). What must NEVER happen is
    // silently returning the CLEAN fenced JSON while discarding a brace.
    expect($result->json)->not->toBe('{"behaviors": []}');
});
