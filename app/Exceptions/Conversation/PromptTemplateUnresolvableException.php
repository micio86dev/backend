<?php

declare(strict_types=1);

namespace App\Exceptions\Conversation;

/**
 * Thrown when no usable prompt set can be resolved for a composition
 * (db-driven-conversation-prompts, design N-6/N-9).
 *
 * A CompositionException, which the interview controller maps to 422
 * `composition_error` (`PromptCutoverTest` proves it through `/start`, with no
 * session row and no provider call). A missing or damaged set is a hard failure,
 * never a silent fallback to other text; the controller also reports it, so it
 * reaches error tracking.
 *
 * {@see $reason} is the machine-readable cause, one constant per failure.
 * Messages name sets, locales, keys and tokens only; they NEVER include a
 * template or override body, which is operator-authored text and ends up in
 * error tracking.
 */
class PromptTemplateUnresolvableException extends CompositionException
{
    public const NO_ACTIVE_SET = 'no_active_set';

    public const LOCALE_MISSING = 'locale_missing';

    public const KEYS_INCOMPLETE = 'keys_incomplete';

    public const CONTRACT_VIOLATED = 'contract_violated';

    public const SEAL_MISMATCH = 'seal_mismatch';

    public const OVERRIDE_INVALID = 'override_invalid';

    public const AMBIGUOUS_ACTIVE_SET = 'ambiguous_active_set';

    public const DUPLICATE_ROW = 'duplicate_row';

    public const EMPTY_SET = 'empty_set';

    public const INVALID_SOURCE = 'invalid_source';

    final protected function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /**
     * @param  string  $value  The configured `conversation.prompt_source`; it is configuration, not operator-authored text.
     */
    public static function invalidSource(string $value): self
    {
        return new self(self::INVALID_SOURCE, "conversation.prompt_source is [{$value}]; it must be one of [db, baseline].");
    }

    public static function noActiveSet(): self
    {
        return new self(self::NO_ACTIVE_SET, 'No conversation prompt set is active.');
    }

    /**
     * @param  list<int>  $setIds  The ids of the sets that are all marked active.
     */
    public static function ambiguousActiveSet(array $setIds): self
    {
        return new self(self::AMBIGUOUS_ACTIVE_SET, 'More than one conversation prompt set is active: ['.implode(', ', $setIds).'].');
    }

    /**
     * @param  string  $what  Names the duplicated row by key, competency and role only, never by body.
     */
    public static function duplicateRow(string $setLabel, string $locale, string $what): self
    {
        return new self(self::DUPLICATE_ROW, "Prompt set [{$setLabel}] locale [{$locale}] holds more than one row for {$what}.");
    }

    public static function emptySet(string $setLabel): self
    {
        return new self(self::EMPTY_SET, "Prompt set [{$setLabel}] holds no fragments at all.");
    }

    public static function localeMissing(string $setLabel, string $locale): self
    {
        return new self(self::LOCALE_MISSING, "Prompt set [{$setLabel}] has no fragments for locale [{$locale}].");
    }

    /**
     * @param  list<string>  $missing  Keys of the enum with no stored fragment.
     * @param  list<string>  $unknown  Stored keys outside the enum.
     */
    public static function keysIncomplete(string $setLabel, string $locale, array $missing, array $unknown): self
    {
        return new self(self::KEYS_INCOMPLETE, sprintf(
            'Prompt set [%s] locale [%s] does not hold exactly the fragment keys. Missing: [%s]. Unknown: [%s].',
            $setLabel,
            $locale,
            implode(', ', $missing),
            implode(', ', $unknown),
        ));
    }

    /**
     * @param  list<string>  $violations  Messages from `PromptFragmentContract`; they name keys and tokens, never bodies.
     */
    public static function contractViolated(string $setLabel, string $locale, array $violations): self
    {
        return new self(self::CONTRACT_VIOLATED, sprintf(
            'Prompt set [%s] locale [%s] breaks the placeholder contract: %s.',
            $setLabel,
            $locale,
            implode('; ', $violations),
        ));
    }

    public static function sealMismatch(string $setLabel): self
    {
        return new self(self::SEAL_MISMATCH, "Prompt set [{$setLabel}] does not match its content seal; its stored text was altered.");
    }

    /**
     * @param  list<string>  $violations
     */
    public static function overrideInvalid(string $setLabel, string $locale, string $competencyCode, array $violations): self
    {
        return new self(self::OVERRIDE_INVALID, sprintf(
            'Prompt set [%s] locale [%s] override for competency [%s] is invalid: %s.',
            $setLabel,
            $locale,
            $competencyCode,
            implode('; ', $violations),
        ));
    }
}
