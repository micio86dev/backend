<?php

namespace App\Contracts;

use App\DTOs\LLMResponse;

/**
 * LLM provider contract (D36 — cost-aware AI testing infrastructure).
 *
 * All scoring code (C8) depends on this interface via dependency injection.
 * In APP_ENV=testing, the container binds FakeLLMProvider instead of any
 * real provider — zero HTTP requests to external AI APIs during standard tests.
 *
 * The @ai Pest group is the ONLY place real provider implementations are used.
 * Those tests run only in the ai-integration.yml workflow (workflow_dispatch or
 * release/* branches) — never on PR or develop push.
 */
interface LLMProvider
{
    /**
     * Send a prompt to the LLM and return a structured response.
     *
     * @param  string               $prompt  The full prompt string (system + user messages pre-composed).
     * @param  array<string, mixed> $options Provider-specific options (temperature, max_tokens, model, etc.).
     */
    public function complete(string $prompt, array $options = []): LLMResponse;
}
