<?php

declare(strict_types=1);

/**
 * AnthropicLLMProvider unit tests (C9 — LLM Binding, D7 resolved).
 *
 * Tests the production LLMProvider binding that calls the Anthropic Messages API
 * directly via Laravel's Http client — NO third-party SDK.
 *
 * All tests use Http::fake() for determinism (zero real API calls in the standard suite).
 * The @ai group test hits the real API and is skipped unless ANTHROPIC_API_KEY is set.
 *
 * Behaviors covered:
 *   - Request shape: correct URL, headers (x-api-key, anthropic-version, content-type), body
 *   - temperature=0 always (determinism invariant — cannot be overridden by $options)
 *   - model from config or $options override
 *   - Prompt mapped to system + messages[{role:user, content}]
 *   - LLMResponse parsing: content (joined text blocks), model, input/output tokens, finishReason
 *   - Non-2xx response → throws AnthropicException (retryable)
 *   - Empty content array → throws AnthropicException (terminal)
 *   - max_tokens from config
 */

use App\Contracts\LLMProvider;
use App\DTOs\LLMResponse;
use App\Exceptions\LLM\AnthropicException;
use App\Services\LLM\AnthropicLLMProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'scoring.model_version' => 'claude-haiku-4-5-20251001',
        'scoring.anthropic.api_key' => 'test-anthropic-key-xyz',
        'scoring.anthropic.base_url' => 'https://api.anthropic.com',
        'scoring.anthropic.version' => '2023-06-01',
        'scoring.anthropic.max_tokens' => 2048,
        'scoring.anthropic.timeout_seconds' => 60,
    ]);
});

// ---------------------------------------------------------------------------
// Contract
// ---------------------------------------------------------------------------

test('AnthropicLLMProvider implements LLMProvider contract', function (): void {
    expect(new AnthropicLLMProvider)->toBeInstanceOf(LLMProvider::class);
});

// ---------------------------------------------------------------------------
// Request shape (Http::fake — deterministic, zero real calls)
// ---------------------------------------------------------------------------

test('complete() sends POST to the Anthropic Messages API URL', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(anthropicSuccessResponse(), 200),
    ]);

    (new AnthropicLLMProvider)->complete('Evaluate COM competency.');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), 'api.anthropic.com/v1/messages');
    });
});

test('complete() sends required headers: x-api-key, anthropic-version, content-type', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(anthropicSuccessResponse(), 200),
    ]);

    (new AnthropicLLMProvider)->complete('Evaluate COM competency.');

    Http::assertSent(function (Request $request): bool {
        return $request->header('x-api-key')[0] === 'test-anthropic-key-xyz'
            && $request->header('anthropic-version')[0] === '2023-06-01'
            && str_contains($request->header('content-type')[0] ?? '', 'application/json');
    });
});

test('complete() always sends temperature=0 (determinism invariant)', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(anthropicSuccessResponse(), 200),
    ]);

    // Even if caller passes temperature in $options, it must be 0
    (new AnthropicLLMProvider)->complete('Evaluate.', ['temperature' => 1.0]);

    Http::assertSent(function (Request $request): bool {
        return $request->data()['temperature'] === 0;
    });
});

test('complete() maps prompt to system + messages body', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(anthropicSuccessResponse(), 200),
    ]);

    (new AnthropicLLMProvider)->complete('Rate COM indicator 1.');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return isset($body['system'])
            && $body['system'] === 'Rate COM indicator 1.'
            && isset($body['messages'])
            && is_array($body['messages'])
            && count($body['messages']) === 1
            && $body['messages'][0]['role'] === 'user'
            && is_string($body['messages'][0]['content']);
    });
});

test('complete() uses model from config(scoring.model_version) by default', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(anthropicSuccessResponse(), 200),
    ]);

    (new AnthropicLLMProvider)->complete('Prompt.');

    Http::assertSent(function (Request $request): bool {
        return $request->data()['model'] === 'claude-haiku-4-5-20251001';
    });
});

test('complete() uses model from $options[model] when provided', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(anthropicSuccessResponse(), 200),
    ]);

    (new AnthropicLLMProvider)->complete('Prompt.', ['model' => 'claude-opus-4-5-20251001']);

    Http::assertSent(function (Request $request): bool {
        return $request->data()['model'] === 'claude-opus-4-5-20251001';
    });
});

test('complete() sends max_tokens from config', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(anthropicSuccessResponse(), 200),
    ]);

    (new AnthropicLLMProvider)->complete('Prompt.');

    Http::assertSent(function (Request $request): bool {
        return $request->data()['max_tokens'] === 2048;
    });
});

// ---------------------------------------------------------------------------
// LLMResponse parsing
// ---------------------------------------------------------------------------

test('complete() parses content from response text blocks (joined)', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => 'Block one. '],
                ['type' => 'text', 'text' => 'Block two.'],
            ],
            'model' => 'claude-haiku-4-5-20251001',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 120, 'output_tokens' => 45],
        ], 200),
    ]);

    $response = (new AnthropicLLMProvider)->complete('Prompt.');

    expect($response)->toBeInstanceOf(LLMResponse::class);
    expect($response->content)->toBe('Block one. Block two.');
});

test('complete() parses model, inputTokens, outputTokens, finishReason from response', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(anthropicSuccessResponse(), 200),
    ]);

    $response = (new AnthropicLLMProvider)->complete('Prompt.');

    expect($response->model)->toBe('claude-haiku-4-5-20251001');
    expect($response->inputTokens)->toBe(200);
    expect($response->outputTokens)->toBe(80);
    expect($response->finishReason)->toBe('end_turn');
});

test('complete() sets truncated=true when stop_reason is max_tokens (A1.9, D3)', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"partial"']],
            'model' => 'claude-haiku-4-5-20251001',
            'stop_reason' => 'max_tokens',
            'usage' => ['input_tokens' => 200, 'output_tokens' => 2048],
        ], 200),
    ]);

    $response = (new AnthropicLLMProvider)->complete('Prompt.');

    expect($response->truncated)->toBeTrue()
        ->and($response->finishReason)->toBe('max_tokens', 'the raw stop_reason string must be carried unchanged');
});

test('complete() sets truncated=false when stop_reason is end_turn', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(anthropicSuccessResponse(), 200),
    ]);

    $response = (new AnthropicLLMProvider)->complete('Prompt.');

    expect($response->truncated)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Error paths
// ---------------------------------------------------------------------------

test('complete() throws AnthropicException on non-2xx response', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(['error' => ['message' => 'Overloaded']], 529),
    ]);

    expect(fn () => (new AnthropicLLMProvider)->complete('Prompt.'))
        ->toThrow(AnthropicException::class);
});

test('complete() throws AnthropicException on 401 unauthorized', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(['error' => ['message' => 'Unauthorized']], 401),
    ]);

    expect(fn () => (new AnthropicLLMProvider)->complete('Prompt.'))
        ->toThrow(AnthropicException::class);
});

test('complete() throws AnthropicException when content array is empty', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [],
            'model' => 'claude-haiku-4-5-20251001',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 0],
        ], 200),
    ]);

    expect(fn () => (new AnthropicLLMProvider)->complete('Prompt.'))
        ->toThrow(AnthropicException::class);
});

test('AnthropicException message includes context (status or reason)', function (): void {
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response(['error' => ['message' => 'Rate limit']], 429),
    ]);

    $caught = null;

    try {
        (new AnthropicLLMProvider)->complete('Prompt.');
    } catch (AnthropicException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull('Expected AnthropicException was not thrown');
    expect($caught?->getMessage())->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// @ai group — real API (skipped unless ANTHROPIC_API_KEY is set)
// ---------------------------------------------------------------------------

test('real Anthropic API call returns a valid LLMResponse', function (): void {
    // Override the fake key from beforeEach with the real key from the environment.
    // getenv() reads the OS-level env var, bypassing any config() override.
    $realKey = (string) getenv('ANTHROPIC_API_KEY');
    config(['scoring.anthropic.api_key' => $realKey]);

    $response = (new AnthropicLLMProvider)->complete(
        'You are a BARS scoring evaluator. Return JSON only: {"behaviors":[{"indicator":"test","score":5,"explanation":"test","excerpts":[]}]}',
        ['model' => 'claude-haiku-4-5-20251001'],
    );

    expect($response)->toBeInstanceOf(LLMResponse::class);
    expect($response->content)->not->toBeEmpty();
    expect($response->model)->not->toBeEmpty();
    expect($response->inputTokens)->toBeGreaterThan(0);
    expect($response->outputTokens)->toBeGreaterThan(0);
    expect($response->finishReason)->not->toBeEmpty();
})->group('ai')->skip(
    // getenv() reads the OS-level env var at test-evaluation time, not the config()
    // value that beforeEach() overrides. This correctly reflects whether the real
    // ANTHROPIC_API_KEY is present in the environment (set in .env or CI secrets).
    fn (): bool => empty(getenv('ANTHROPIC_API_KEY')),
    'ANTHROPIC_API_KEY not set — skipping real API integration test.',
);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Returns a canonical Anthropic Messages API success response for use in fakes.
 *
 * @return array<string, mixed>
 */
function anthropicSuccessResponse(): array
{
    return [
        'content' => [
            ['type' => 'text', 'text' => '{"behaviors":[{"indicator":"test","score":5,"explanation":"ok","excerpts":["verbatim text"]}]}'],
        ],
        'model' => 'claude-haiku-4-5-20251001',
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 200, 'output_tokens' => 80],
    ];
}
