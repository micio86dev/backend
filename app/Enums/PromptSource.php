<?php

declare(strict_types=1);

namespace App\Enums;

use App\Exceptions\Conversation\PromptTemplateUnresolvableException;

/**
 * Where the conversation prompt text comes from
 * (db-driven-conversation-prompts, design N-9).
 *
 * `db` is the normal source: the ACTIVE stored prompt set. `baseline` is the
 * break-glass: the code baseline, with no database read, for an operator who
 * must keep interviews running while the stored set is being repaired.
 *
 * The configured value is validated on first use and never normalised or
 * defaulted: an unknown value (a typo, an empty variable) is a hard composition
 * failure that names the accepted values, because silently choosing a source
 * would serve text the operator did not select.
 */
enum PromptSource: string
{
    case Db = 'db';
    case Baseline = 'baseline';

    /**
     * @throws PromptTemplateUnresolvableException When the configured value is not a source.
     */
    public static function configured(): self
    {
        $value = config('conversation.prompt_source');

        return (is_string($value) ? self::tryFrom($value) : null)
            ?? throw PromptTemplateUnresolvableException::invalidSource(is_scalar($value) ? (string) $value : get_debug_type($value));
    }
}
