<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a registered conversation-LLM model in `llm_models` can do
 * (pluggable-conversation-llm, design D1).
 *
 * `llm_models` carries no `mode` column — a stored `mode` alongside
 * `capability` would be a second source of truth for a 1:1 relation, and the
 * one that disagreed would be the one the picker reads. `mode()` derives it
 * instead, as an exhaustive `match` with DELIBERATELY no default arm: a
 * future capability added here without a matching `mode()` arm must fail
 * loudly (`\UnhandledMatchError`), not silently resolve to a mode nobody
 * chose.
 */
enum LlmCapability: string
{
    case Text = 'text';
    case NativeDuplex = 'native_duplex';

    public function mode(): LlmMode
    {
        return match ($this) {
            self::Text => LlmMode::Managed,
            self::NativeDuplex => LlmMode::NativeDuplex,
        };
    }
}
