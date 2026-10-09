<?php

declare(strict_types=1);

namespace App\DTOs\Conversation;

/**
 * The outcome of resolving the active stored prompt set for one
 * (locale, competency, role): the verified templates, the operator override that
 * applies (if any) and the identity of the set they came from
 * (db-driven-conversation-prompts, design N-6/N-8).
 */
final readonly class ResolvedPromptSet
{
    public function __construct(
        public PromptTemplateSet $templates,
        /** Plain-text override for the competency, or null when none applies. */
        public ?string $override,
        public string $setLabel,
        public int $setId,
        public string $contentSha256,
    ) {}

    /**
     * The durable reference stamped on a session: `{label}+s{id}.{sha12}`.
     * The short hash pins the exact content, so two sets with one label differ.
     */
    public function ref(): string
    {
        return $this->setLabel.'+'.$this->stampRef();
    }

    /**
     * The label-free reference `s{id}.{sha12}` that the durable session stamp
     * appends to the configured prompt version.
     */
    public function stampRef(): string
    {
        return sprintf('s%d.%s', $this->setId, substr($this->contentSha256, 0, 12));
    }
}
