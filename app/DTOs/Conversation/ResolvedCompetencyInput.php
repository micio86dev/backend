<?php

declare(strict_types=1);

namespace App\DTOs\Conversation;

/**
 * One competency, already resolved, as `SystemPromptComposer::composeMany()` consumes it
 * (tavus-single-session-interview API-02, design A8/N13).
 *
 * Carries every argument of `SystemPromptComposer::compose()`, in the same names, so the
 * composer can call `compose()` per entry and each segment is byte-identical to the
 * single-competency prompt. The stored prompt set cannot be identified from
 * {@see PromptTemplateSet} alone, so its `s{id}.{sha12}` reference travels beside it
 * (`ResolvedPromptSet::stampRef()`; null for the code baseline).
 */
final readonly class ResolvedCompetencyInput
{
    /**
     * @param  list<string>  $primaryQuestions
     * @param  string|null  $promptSetRef  Identity of `$templates`; every entry of a plan must agree.
     */
    public function __construct(
        public string $competencyCode,
        public ?int $roleId,
        public int $competencyId,
        public string $projectLocale,
        public int $followUpBudget,
        public ?int $nudgeMinChars,
        public ?string $advancePhrase = null,
        public ?int $minQuestions = null,
        public array $primaryQuestions = [],
        public ?SpokenOpening $spokenOpening = null,
        public ?int $revisionId = null,
        public ?PromptTemplateSet $templates = null,
        public ?string $override = null,
        public ?string $promptSetRef = null,
    ) {}
}
