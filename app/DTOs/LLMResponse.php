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
 * - truncated:    Whether the response was cut off at the provider's max-output-tokens
 *                 budget (scoring-failure-containment D3). Additive, defaults to false —
 *                 every existing call site keeps today's meaning unchanged. Detected from
 *                 the RAW provider signal (e.g. Anthropic's `stop_reason === 'max_tokens'`);
 *                 `finishReason` still carries that raw string unchanged, so this field is
 *                 the ONE decision derived from it: "was the output cut off".
 */
final readonly class LLMResponse
{
    public function __construct(
        public string $content,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public string $finishReason,
        public bool $truncated = false,
    ) {}
}
