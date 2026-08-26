<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a bound conversation-LLM model can be used FOR
 * (pluggable-conversation-llm, `managed` mode).
 *
 * Derived from `LlmCapability`, never stored: `llm_models` carries no `mode`
 * column (design D1). Only `Managed` ships in this change — a model whose
 * capability resolves to `NativeDuplex` is registered, priced, and inert:
 * selectable in no picker and savable by no write path
 * (`UnsupportedLlmModeException`, 422 `mode_unsupported`). Change 2 relaxes
 * exactly one `match` arm to enable it.
 */
enum LlmMode: string
{
    case Managed = 'managed';
    case NativeDuplex = 'native_duplex';
}
