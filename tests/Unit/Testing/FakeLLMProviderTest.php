<?php

/**
 * FakeLLMProvider tests (D36 — cost-aware AI testing infrastructure).
 *
 * RED phase: these tests fail because LLMProvider, LLMResponse, and
 * FakeLLMProvider do not exist yet.
 *
 * Tests verify:
 * - FakeLLMProvider implements LLMProvider
 * - complete() returns an LLMResponse DTO
 * - The response carries expected fields (content, model, usage, finish_reason)
 * - A configured fake response is returned verbatim
 * - The fake generates zero HTTP requests to any external AI endpoint
 */

use App\Contracts\LLMProvider;
use App\DTOs\LLMResponse;
use App\Testing\FakeLLMProvider;

test('FakeLLMProvider implements LLMProvider contract', function (): void {
    $fake = new FakeLLMProvider();

    expect($fake)->toBeInstanceOf(LLMProvider::class);
});

test('complete() returns an LLMResponse DTO', function (): void {
    $fake = new FakeLLMProvider();
    $response = $fake->complete('Evaluate this BARS response.');

    expect($response)->toBeInstanceOf(LLMResponse::class);
});

test('LLMResponse carries required fields', function (): void {
    $fake = new FakeLLMProvider();
    $response = $fake->complete('Rate competency COM.');

    expect($response->content)->toBeString()->not->toBeEmpty();
    expect($response->model)->toBeString()->not->toBeEmpty();
    expect($response->inputTokens)->toBeInt()->toBeGreaterThanOrEqual(0);
    expect($response->outputTokens)->toBeInt()->toBeGreaterThanOrEqual(0);
    expect($response->finishReason)->toBeString()->not->toBeEmpty();
});

test('FakeLLMProvider returns the configured response', function (): void {
    $fake = new FakeLLMProvider(content: 'Mocked BARS output', model: 'fake-model-v1');
    $response = $fake->complete('Evaluate COM competency.');

    expect($response->content)->toBe('Mocked BARS output');
    expect($response->model)->toBe('fake-model-v1');
});

test('FakeLLMProvider records calls without making HTTP requests', function (): void {
    $fake = new FakeLLMProvider();
    $fake->complete('First prompt');
    $fake->complete('Second prompt');

    expect($fake->callCount())->toBe(2);
    // No HTTP requests are made — the fake is purely in-memory
    expect($fake->httpRequestCount())->toBe(0);
});
