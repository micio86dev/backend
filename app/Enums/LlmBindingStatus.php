<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The resolved billing status of a session's conversation-LLM binding
 * (pluggable-conversation-llm, design D0).
 *
 * `Applied` is the ONLY billable value. `applied ⇔ binding present ∧
 * credential resolvable ∧ llm_sync_status === 'synced'` — decided from
 * PERSISTED state only, never HTTP, so the resolver stays pure.
 */
enum LlmBindingStatus: string
{
    case Applied = 'applied';
    case Unbound = 'unbound';
    case Degraded = 'degraded';
}
