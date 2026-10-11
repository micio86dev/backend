<?php

declare(strict_types=1);

namespace App\Exceptions\Conversation;

use RuntimeException;

/**
 * Publishing or activating a prompt set was refused
 * (db-driven-conversation-prompts PR6b).
 *
 * Console-side only: no HTTP surface calls the write side. {@see $reason} is the
 * machine-readable cause and {@see $violations} the individual findings. Messages
 * and violations name sets, locales, keys and tokens only; they NEVER include a
 * template or override body, which is operator-authored text.
 */
final class PromptSetException extends RuntimeException
{
    public const INVALID = 'invalid';

    public const DUPLICATE_LABEL = 'duplicate_label';

    public const UNKNOWN_SET = 'unknown_set';

    /**
     * @param  list<string>  $violations
     */
    private function __construct(public readonly string $reason, string $message, public readonly array $violations = [])
    {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $violations
     */
    public static function invalid(array $violations): self
    {
        return new self(self::INVALID, 'The prompt set is invalid: '.implode('; ', $violations).'.', $violations);
    }

    public static function duplicateLabel(string $label): self
    {
        return new self(self::DUPLICATE_LABEL, "A prompt set labelled [{$label}] already exists.");
    }

    public static function unknownSet(int|string $ref): self
    {
        return new self(self::UNKNOWN_SET, "No prompt set matches [{$ref}].");
    }
}
