<?php

/**
 * CassetteFactory tests (D36 — VCR cassette pattern).
 *
 * Verifies:
 * - withCassette() loads the fixture and returns a configured FakeLLMProvider
 * - The provider replays the cassette content verbatim
 * - An invalid cassette name throws a RuntimeException
 */

use App\DTOs\LLMResponse;

test('withCassette() returns a FakeLLMProvider configured from fixture', function (): void {
    $fake = $this->withCassette('bars-eval--haiku-4-5--prompt-v1');
    $response = $fake->complete('Evaluate COM competency.');

    expect($response)->toBeInstanceOf(LLMResponse::class);
    expect($response->model)->toBe('claude-haiku-4-5-20251001');
    expect($response->finishReason)->toBe('stop');
    expect($response->inputTokens)->toBe(1247);
    expect($response->outputTokens)->toBe(198);
    expect($response->content)->toContain('COM');
});

test('withCassette() replays content verbatim', function (): void {
    $fake = $this->withCassette('bars-eval--haiku-4-5--prompt-v1');
    $response = $fake->complete('Any prompt — cassette content is always returned.');

    expect($response->content)->toContain('3.67');
});

test('withCassette() throws RuntimeException for unknown cassette', function (): void {
    $this->withCassette('non-existent-cassette--v99');
})->throws(\RuntimeException::class, 'Cassette not found');
