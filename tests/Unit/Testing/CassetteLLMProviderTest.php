<?php

declare(strict_types=1);

/**
 * RED — A1.1: CassetteLLMProvider per-call variation (design.md D8).
 *
 * Today the cassette map is `array<string, string>` — one fixed response per
 * competency_code, used for every call to that competency. D8's truncation
 * retry needs a cassette entry that varies ACROSS CALLS to the same
 * competency (e.g. "first call truncated, second call complete"), so the
 * value type widens to `string|CassetteResponse|list<CassetteResponse>`:
 *
 * - bare `string`         → today's meaning, unchanged.
 * - `CassetteResponse`    → one call, with an explicit finishReason override.
 * - `list<CassetteResponse>` → consumed IN CALL ORDER for that competency_code.
 */

use App\Testing\CassetteLLMProvider;
use App\Testing\CassetteResponse;

test('a bare string cassette entry keeps today\'s meaning', function (): void {
    $cassette = new CassetteLLMProvider(['COL' => '{"behaviors": []}']);

    $response = $cassette->complete('prompt', ['competency_code' => 'COL']);

    expect($response->content)->toBe('{"behaviors": []}')
        ->and($response->finishReason)->toBe('stop');
});

test('a single CassetteResponse entry carries its own finishReason override', function (): void {
    $cassette = new CassetteLLMProvider([
        'PRS' => new CassetteResponse(content: '{"partial": true}', finishReason: 'max_tokens'),
    ]);

    $response = $cassette->complete('prompt', ['competency_code' => 'PRS']);

    expect($response->content)->toBe('{"partial": true}')
        ->and($response->finishReason)->toBe('max_tokens');
});

test('a list<CassetteResponse> entry is consumed in call order per competency_code', function (): void {
    $cassette = new CassetteLLMProvider([
        'PRS' => [
            new CassetteResponse(content: '{"partial": true}', finishReason: 'max_tokens'),
            new CassetteResponse(content: '{"behaviors": []}', finishReason: 'end_turn'),
        ],
    ]);

    $first = $cassette->complete('prompt', ['competency_code' => 'PRS']);
    $second = $cassette->complete('prompt', ['competency_code' => 'PRS']);

    expect($first->content)->toBe('{"partial": true}')
        ->and($first->finishReason)->toBe('max_tokens')
        ->and($second->content)->toBe('{"behaviors": []}')
        ->and($second->finishReason)->toBe('end_turn');
});

test('a list<CassetteResponse> entry keyed by a DIFFERENT competency does not share call-order state', function (): void {
    $cassette = new CassetteLLMProvider([
        'PRS' => [
            new CassetteResponse(content: 'first', finishReason: 'max_tokens'),
            new CassetteResponse(content: 'second', finishReason: 'end_turn'),
        ],
        'COL' => '{"behaviors": []}',
    ]);

    $prsFirst = $cassette->complete('prompt', ['competency_code' => 'PRS']);
    $colOnly = $cassette->complete('prompt', ['competency_code' => 'COL']);
    $prsSecond = $cassette->complete('prompt', ['competency_code' => 'PRS']);

    expect($prsFirst->content)->toBe('first')
        ->and($colOnly->content)->toBe('{"behaviors": []}')
        ->and($prsSecond->content)->toBe('second');
});

test('exhausting a list<CassetteResponse> entry throws LogicException naming the competency and call index', function (): void {
    $cassette = new CassetteLLMProvider([
        'PRS' => [new CassetteResponse(content: 'only-call', finishReason: 'max_tokens')],
    ]);

    $cassette->complete('prompt', ['competency_code' => 'PRS']);

    expect(fn () => $cassette->complete('prompt', ['competency_code' => 'PRS']))
        ->toThrow(LogicException::class);
});
