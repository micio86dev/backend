<?php

declare(strict_types=1);

namespace App\Services\LLM;

use App\Contracts\LLMProvider;
use App\DTOs\LLMResponse;
use App\Exceptions\LLM\AnthropicException;
use Illuminate\Support\Facades\Http;

/**
 * Production LLMProvider binding — Anthropic Messages API (C9 D7, resolved).
 *
 * Calls POST https://api.anthropic.com/v1/messages directly via Laravel's Http
 * client. NO third-party SDK — therefore NO D25 dependency to pin and no
 * Dependency-Resolution-Policy blocker.
 *
 * Config keys (config/scoring.php → 'anthropic'):
 *   api_key          — ANTHROPIC_API_KEY env var (never hardcoded)
 *   base_url         — https://api.anthropic.com (overridable for test/staging)
 *   version          — anthropic-version header (e.g. '2023-06-01')
 *   max_tokens       — default max_tokens for responses
 *   timeout_seconds  — Http client timeout
 *
 * Model selection (in order):
 *   1. $options['model'] — caller override
 *   2. config('scoring.model_version') — pinned versioned ID from config
 *
 * Determinism invariant: temperature=0 is ALWAYS sent and CANNOT be overridden
 * by $options. This is a hard contract for BARS scoring reproducibility.
 *
 * Prompt mapping:
 *   The full $prompt string is sent as the 'system' field (rubric + anchors).
 *   A minimal user message is appended to satisfy the Anthropic API requirement
 *   that 'messages' is non-empty (the actual transcript is encoded in the system prompt).
 *
 * Error classification:
 *   - HTTP 5xx / transport errors → AnthropicException(retryable=true)
 *   - HTTP 4xx / empty content / structural failures → AnthropicException(retryable=false)
 */
final class AnthropicLLMProvider implements LLMProvider
{
    private const MESSAGES_PATH = '/v1/messages';

    /**
     * Send a prompt to the Anthropic Messages API and return a structured response.
     *
     * @param  string  $prompt  Full prompt (system rubric + injected BARS anchors).
     * @param  array<string, mixed>  $options  Optional overrides: model, max_tokens.
     *
     * @throws AnthropicException on non-2xx responses, transport errors, or empty content.
     */
    public function complete(string $prompt, array $options = []): LLMResponse
    {
        $model = (string) ($options['model'] ?? config('scoring.model_version', 'claude-haiku-4-5-20251001'));
        $maxTokens = (int) ($options['max_tokens'] ?? config('scoring.anthropic.max_tokens', 2048));

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => 0,                // Hard invariant — never override
            'system' => $prompt,
            'messages' => [
                ['role' => 'user', 'content' => 'Evaluate the candidate response per the rubric above.'],
            ],
        ];

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout((int) config('scoring.anthropic.timeout_seconds', 60))
                ->post($this->buildUrl(), $body);
        } catch (\Throwable $e) {
            throw new AnthropicException(
                message: 'Anthropic API transport error: '.$e->getMessage(),
                retryable: true,
                previous: $e,
            );
        }

        $status = $response->status();

        if (! $response->successful()) {
            $retryable = $status >= 500;
            $body = (string) $response->body();
            throw new AnthropicException(
                message: "Anthropic API returned HTTP {$status}: {$body}",
                retryable: $retryable,
            );
        }

        return $this->parseResponse($response->json());
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        return [
            'x-api-key' => (string) config('scoring.anthropic.api_key', ''),
            'anthropic-version' => (string) config('scoring.anthropic.version', '2023-06-01'),
            'content-type' => 'application/json',
        ];
    }

    private function buildUrl(): string
    {
        $base = rtrim((string) config('scoring.anthropic.base_url', 'https://api.anthropic.com'), '/');

        return $base.self::MESSAGES_PATH;
    }

    /**
     * Parse the Anthropic Messages API response into an LLMResponse DTO.
     *
     * @param  array<string, mixed>|null  $data
     *
     * @throws AnthropicException on empty or malformed content.
     */
    private function parseResponse(?array $data): LLMResponse
    {
        $blocks = $data['content'] ?? [];

        if (! is_array($blocks) || $blocks === []) {
            throw new AnthropicException(
                message: 'Anthropic API returned an empty content array — no text blocks to parse.',
                retryable: false,
            );
        }

        // Join all text-type blocks into a single content string.
        $text = implode('', array_map(
            static fn (mixed $block): string => (is_array($block) && $block['type'] === 'text')
                ? (string) ($block['text'] ?? '')
                : '',
            $blocks,
        ));

        $stopReason = (string) ($data['stop_reason'] ?? '');

        return new LLMResponse(
            content: $text,
            model: (string) ($data['model'] ?? ''),
            inputTokens: (int) (($data['usage'] ?? [])['input_tokens'] ?? 0),
            outputTokens: (int) (($data['usage'] ?? [])['output_tokens'] ?? 0),
            finishReason: $stopReason,
            // D3 — the ONE decision derived from the raw stop_reason. finishReason
            // above still carries the raw string unchanged for anyone doing forensics.
            truncated: $stopReason === 'max_tokens',
        );
    }
}
