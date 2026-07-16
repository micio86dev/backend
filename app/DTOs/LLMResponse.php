<?php

namespace App\DTOs;

/**
 * LLM response data transfer object (D36).
 *
 * Carries the normalized response from any LLM provider implementation.
 * Used by all scoring code (C8) and verified by FakeLLMProvider in tests.
 *
 * Fields:
 * - content:      The raw text content returned by the model.
 * - model:        The model identifier (e.g. "claude-haiku-4-5-20251001").
 * - inputTokens:  Number of tokens in the prompt (for cost tracking).
 * - outputTokens: Number of tokens in the response (for cost tracking).
 * - finishReason: Why the model stopped ("stop", "length", "content_filter", etc.).
 */
final readonly class LLMResponse
{
    public function __construct(
        public string $content,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public string $finishReason,
    ) {}
}
