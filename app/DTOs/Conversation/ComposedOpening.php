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
     * @throws \InvalidArgumentException When text or version is empty or whitespace-only.
     */
    public function __construct(
        public string $text,
        public string $version,
    ) {
        if (self::isBlank($text)) {
            throw new \InvalidArgumentException('ComposedOpening: text must be non-empty.');
        }

        if (self::isBlank($version)) {
            throw new \InvalidArgumentException('ComposedOpening: version must be non-empty.');
        }
    }

    /**
     * Unicode-aware blank check: trim() strips only ASCII whitespace, so NBSP,
     * ideographic space, zero-width space and narrow NBSP would pass as text.
     * Invalid UTF-8 makes preg_match return false, which counts as non-blank.
     */
    private static function isBlank(string $value): bool
    {
        return preg_match('/^[\s\p{Z}\p{Cf}]*$/u', $value) === 1;
    }
}
