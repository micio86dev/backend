<?php

declare(strict_types=1);

namespace App\DTOs\Scoring;

/**
 * Data Transfer Object carrying the built prompt and LLM call options.
 *
 * Produced by PromptBuilder and consumed by the scoring loop to invoke LLMProvider.
 *
 * REQ: PromptBuilder output (C9 D3)
 */
final readonly class PromptPayload
{
    /**
     * @param  array<string, mixed>  $options  LLM options (temperature=0, model, etc.).
     */
    public function __construct(
        public string $systemPrompt,
        public string $userMessage,
        public array $options,
    ) {}
}
