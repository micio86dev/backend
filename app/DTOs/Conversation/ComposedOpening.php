<?php

declare(strict_types=1);

namespace App\DTOs\Conversation;

/**
 * Value object produced by OpeningTextComposer.
 *
 * Carries the composed opening-greeting text and the template version that
 * produced it. Mirrors ComposedPrompt's shape (immutable, non-empty invariants)
 * so both composed strings ship with the same traceability guarantee — but this
 * is a SEPARATE DTO on purpose: an opening greeting is UX chrome, not a scoring
 * instruction, and the two must never be conflated (design D9).
 *
 * REQ: ComposedOpening value object (PR3 — design D9)
 */
final readonly class ComposedOpening
{
    /**
     * @param  string  $text  The composed opening-greeting string (non-empty).
     * @param  string  $version  Template version that produced this greeting (non-empty).
     *
     * @throws \InvalidArgumentException When text or version is empty.
     */
    public function __construct(
        public string $text,
        public string $version,
    ) {
        if ($text === '') {
            throw new \InvalidArgumentException('ComposedOpening: text must be non-empty.');
        }

        if ($version === '') {
            throw new \InvalidArgumentException('ComposedOpening: version must be non-empty.');
        }
    }
}
