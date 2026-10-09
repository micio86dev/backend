<?php

declare(strict_types=1);

namespace App\DTOs\Conversation;

use App\Models\ConversationPromptSet;

/**
 * The outcome of activating a prompt set (db-driven-conversation-prompts PR6b):
 * the set, and whether this call changed which set is active. `changed` is false
 * when the set was already the active one. It is decided inside the locked
 * activation transaction, so it cannot be stale under concurrency.
 */
final readonly class PromptSetActivation
{
    public function __construct(
        public ConversationPromptSet $set,
        public bool $changed,
    ) {}
}
