<?php

declare(strict_types=1);

namespace App\DTOs\Conversation;

/**
 * Value object produced by SystemPromptComposer.
 *
 * Carries the composed system-prompt text and the template version that
 * produced it. Immutable by construction (readonly); used for traceability
 * and provider injection.
 *
 * Invariants:
 *   - text must be non-empty (a zero-length prompt is always an authoring bug).
 *   - version must be non-empty (required for per-interview traceability).
 *
 * REQ: ComposedPrompt value object (C8 Phase 1)
 */
final readonly class ComposedPrompt
{
    /**
     * @param  string  $text     The composed system-prompt string (non-empty).
     * @param  string  $version  Template version that produced this prompt (non-empty).
     *
     * @throws \InvalidArgumentException When text or version is empty.
     */
    public function __construct(
        public string $text,
        public string $version,
    ) {
        if ($text === '') {
            throw new \InvalidArgumentException('ComposedPrompt: text must be non-empty.');
        }

        if ($version === '') {
            throw new \InvalidArgumentException('ComposedPrompt: version must be non-empty.');
        }
    }
}
