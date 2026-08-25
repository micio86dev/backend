<?php

declare(strict_types=1);

/**
 * RED — A1.9: LLMResponse gains `$truncated` (C13, design.md D3).
 *
 * Additive, defaulted `false` — every existing call site that constructs an
 * LLMResponse without the new argument keeps today's meaning unchanged.
 */

use App\DTOs\LLMResponse;

test('truncated defaults to false when not passed', function (): void {
    $response = new LLMResponse(
        content: '{"behaviors": []}',
        model: 'claude-haiku-4-5-20251001',
        inputTokens: 100,
        outputTokens: 50,
        finishReason: 'end_turn',
    );

    expect($response->truncated)->toBeFalse();
});

test('truncated can be set explicitly to true', function (): void {
    $response = new LLMResponse(
        content: '{"partial"',
        model: 'claude-haiku-4-5-20251001',
        inputTokens: 100,
        outputTokens: 2048,
        finishReason: 'max_tokens',
        truncated: true,
    );

    expect($response->truncated)->toBeTrue();
});
