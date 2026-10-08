<?php

declare(strict_types=1);

namespace App\DTOs\Conversation;

use App\Enums\PromptFragmentKey;
use InvalidArgumentException;

/**
 * The 31 prompt fragment templates of ONE locale, immutable
 * (db-driven-conversation-prompts, design N-1/N-7).
 *
 * Built only from a COMPLETE key set: a missing key or a key outside
 * {@see PromptFragmentKey} is refused, so a composer holding a set can never
 * meet a fragment that is absent.
 *
 * It holds templates and substitutes values; it does not validate template
 * content (that is `PromptFragmentContract`, applied at publish and
 * resolution) and it performs no IO.
 */
final readonly class PromptTemplateSet
{
    /** @var array<string, string> */
    private array $fragments;

    /**
     * @param  array<array-key, mixed>  $fragments  Template body keyed by `PromptFragmentKey` value.
     *                                              Typed loosely on purpose: the map is loaded
     *                                              from storage, so each body is checked here.
     *
     * @throws InvalidArgumentException When a key is missing, unknown, or a body is not a string.
     */
    public function __construct(array $fragments)
    {
        $expected = array_map(static fn (PromptFragmentKey $key): string => $key->value, PromptFragmentKey::cases());
        $given = array_map('strval', array_keys($fragments));

        $missing = array_diff($expected, $given);
        $unknown = array_diff($given, $expected);

        if ($missing !== [] || $unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'PromptTemplateSet: the key set must be exactly the %d fragment keys. Missing: [%s]. Unknown: [%s].',
                count($expected),
                implode(', ', $missing),
                implode(', ', $unknown),
            ));
        }

        $bodies = [];

        foreach ($fragments as $key => $body) {
            if (! is_string($body)) {
                throw new InvalidArgumentException("PromptTemplateSet: the body of [{$key}] is not a string.");
            }

            $bodies[(string) $key] = $body;
        }

        $this->fragments = $bodies;
    }

    /**
     * Render one fragment: a SINGLE `strtr` pass over the template.
     *
     * Single pass is the injection guard: `strtr` never rescans text it has
     * already substituted, so a value that itself contains `{{budget}}` (an
     * operator's advance phrase or primary question) appears literally.
     *
     * @param  array<string, string|int>  $values  Token value keyed by bare token name.
     *
     * @throws InvalidArgumentException When a required token has no value or a value has no token.
     */
    public function render(PromptFragmentKey $key, array $values = []): string
    {
        $required = $key->requiredTokens();
        $given = array_map('strval', array_keys($values));

        $missing = array_diff($required, $given);
        $unknown = array_diff($given, $required);

        if ($missing !== [] || $unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'PromptTemplateSet: fragment [%s] takes exactly the tokens [%s]. Missing: [%s]. Unknown: [%s].',
                $key->value,
                implode(', ', $required),
                implode(', ', $missing),
                implode(', ', $unknown),
            ));
        }

        $replacements = [];

        foreach ($values as $token => $value) {
            $replacements['{{'.$token.'}}'] = (string) $value;
        }

        return strtr($this->fragments[$key->value], $replacements);
    }
}
