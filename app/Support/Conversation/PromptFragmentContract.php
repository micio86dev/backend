<?php

declare(strict_types=1);

namespace App\Support\Conversation;

use App\Enums\PromptFragmentKey;
use InvalidArgumentException;

/**
 * The placeholder contract of a prompt fragment TEMPLATE
 * (db-driven-conversation-prompts, design N-2).
 *
 * For every key: each required token present, no `{{x}}` outside the key's own
 * set, no stray `{{` or `}}`, no leading or trailing whitespace, not empty.
 * An override body is plain text: it carries no placeholder at all.
 *
 * It validates TEMPLATES, never rendered text: a rendered prompt legitimately
 * contains whatever an operator wrote in a question or an advance phrase.
 *
 * It is a load-bearing substitute for the code-review gate that
 * database-driven prompts remove. An `advance.with_phrase` template without
 * `{{advance_phrase}}` never tells the avatar the sentence to speak, so
 * completion detection never fires and the provider session dies with
 * MAX_DURATION_REACHED, a defect that has already shipped once.
 */
final class PromptFragmentContract
{
    /** A well-formed token: `{{name}}`, no inner spaces. */
    private const TOKEN_PATTERN = '/(?<!\{)\{\{([A-Za-z0-9_]+)\}\}(?!\})/';

    /**
     * Every contract violation of a fragment template, in a stable order;
     * an empty list means the template is valid.
     *
     * @return list<string> Messages that each name the key and, where one applies, the token.
     */
    public function violations(PromptFragmentKey $key, string $body): array
    {
        if (trim($body) === '') {
            return ["fragment [{$key->value}] is empty"];
        }

        $violations = [];

        if ($body !== trim($body)) {
            $violations[] = "fragment [{$key->value}] has leading or trailing whitespace";
        }

        preg_match_all(self::TOKEN_PATTERN, $body, $matches);
        $found = array_values(array_unique($matches[1]));

        foreach (array_diff($key->requiredTokens(), $found) as $token) {
            $violations[] = "fragment [{$key->value}] is missing the required token {{{$token}}}";
        }

        foreach (array_diff($found, $key->requiredTokens()) as $token) {
            $violations[] = "fragment [{$key->value}] contains the unknown token {{{$token}}}";
        }

        if ($this->hasStrayBraces($body)) {
            $violations[] = "fragment [{$key->value}] contains a stray \"{{\" or \"}}\"";
        }

        return $violations;
    }

    /**
     * Violations of an override body: plain text, no placeholder at all.
     *
     * @return list<string>
     */
    public function overrideViolations(string $body): array
    {
        if (trim($body) === '') {
            return ['override is empty'];
        }

        return str_contains($body, '{{') || str_contains($body, '}}')
            ? ['override contains a placeholder or brace pair "{{" / "}}"']
            : [];
    }

    /**
     * @throws InvalidArgumentException With every violation of the template.
     */
    public function assertValid(PromptFragmentKey $key, string $body): void
    {
        $violations = $this->violations($key, $body);

        if ($violations !== []) {
            throw new InvalidArgumentException(implode('; ', $violations));
        }
    }

    private function hasStrayBraces(string $body): bool
    {
        // Strip every well-formed token first: what remains must hold no brace pair.
        $remainder = (string) preg_replace(self::TOKEN_PATTERN, '', $body);

        return str_contains($remainder, '{{') || str_contains($remainder, '}}');
    }
}
