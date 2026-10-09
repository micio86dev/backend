<?php

declare(strict_types=1);

namespace App\DTOs\Conversation;

use App\Enums\PromptFragmentKey;
use InvalidArgumentException;

/**
 * The 32 prompt fragment templates of ONE locale, immutable
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
     * The stored template of one fragment, placeholders intact: what a
     * contract check validates. Rendering goes through {@see render()}.
     */
    public function template(PromptFragmentKey $key): string
    {
        return $this->fragments[$key->value];
    }

    /**
     * Render one fragment: a SINGLE `strtr` pass over the template.
     *
     * Single pass is the injection guard: `strtr` never rescans text it has
     * already substituted, so a value that itself contains `{{budget}}` (an
     * operator's advance phrase or primary question) appears literally.
     *
     * @param  array<string, mixed>  $values  Token value keyed by bare token name; each must be string|int.
     *
     * @throws InvalidArgumentException When a required token has no value, a value has no token,
     *                                  a value is neither a string nor an int,
     *                                  or the `advance_phrase` value is empty.
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
            if (! is_string($value) && ! is_int($value)) {
                throw new InvalidArgumentException("PromptTemplateSet: value of [{$token}] must be string|int.");
            }

            if ($token === 'advance_phrase' && preg_replace('/[\s\x{200B}]+/u', '', (string) $value) === '') {
                // An empty phrase renders `say: ""`: the avatar speaks nothing, completion never
                // fires and the provider session dies with MAX_DURATION_REACHED.
                throw new InvalidArgumentException('PromptTemplateSet: the value of [advance_phrase] must not be empty.');
            }

            $replacements['{{'.$token.'}}'] = (string) $value;
        }

        return strtr($this->fragments[$key->value], $replacements);
    }
}
