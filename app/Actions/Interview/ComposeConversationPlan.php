<?php

declare(strict_types=1);

namespace App\Actions\Interview;

use App\DTOs\Conversation\ConversationPlan;
use App\DTOs\Conversation\SpokenOpening;
use App\Enums\AssessmentType;
use App\Exceptions\Conversation\CompositionException;
use App\Exceptions\Conversation\PromptTemplateUnresolvableException;
use App\Exceptions\Scoring\AnchorTranslationMissingException;
use App\Models\Competency;
use App\Models\Project;
use App\Services\Conversation\SystemPromptComposer;

/**
 * ComposeConversationPlan: the one combined context for the competencies that remain
 * (tavus-single-session-interview API-03b, design A8/N3/N13).
 *
 * Each entry is resolved through {@see ComposeCompetencyPrompt::resolveInput()}, the very
 * code the single-competency path runs (pinned revision, role, stored set, override), so a
 * segment is byte-identical to the single-competency prompt. `composeMany()` then assembles
 * them and refuses entries that came from different stored sets (the active set can flip
 * between two `resolveActive()` calls), which surfaces as a `CompositionException`: the
 * controller's 422 `composition_error`, before any session row or provider call.
 *
 * Any covered competency failing fails the whole plan; the exceptions propagate unchanged
 * so the caller keeps its 422-versus-500 rule and `report()` for an unresolvable set.
 */
final class ComposeConversationPlan
{
    public function __construct(
        private readonly ComposeCompetencyPrompt $composeCompetencyPrompt,
        private readonly ResolvePrimaryQuestions $primaryQuestions,
        private readonly SystemPromptComposer $composer,
    ) {}

    /**
     * @param  list<array{competency_code: string, competency_ordinal: int, total_competencies: int}>  $remaining  Ordered, the next competency first.
     * @param  string  $endPhrase  Ends the turn of every competency but the project's last.
     * @param  string  $finalPhrase  Ends the turn of the project's last competency.
     *
     * @throws CompositionException
     * @throws AnchorTranslationMissingException
     * @throws PromptTemplateUnresolvableException
     */
    public function handle(
        Project $project,
        AssessmentType $assessmentType,
        ?int $revisionId,
        array $remaining,
        int $followUpBudget,
        string $endPhrase,
        string $finalPhrase,
    ): ConversationPlan {
        $inputs = [];

        foreach ($remaining as $entry) {
            $code = $entry['competency_code'];
            $competency = Competency::where('code', $code)->where('revision_id', $revisionId)->first();
            $primaries = $competency === null ? [] : $this->primaryQuestions->handle($project, $competency->id);

            $inputs[] = $this->composeCompetencyPrompt->resolveInput(
                $project,
                $assessmentType,
                $code,
                $revisionId,
                $competency,
                $primaries,
                $followUpBudget,
                // The plan is only ever built for a fresh start, where the opening spoke primary 1.
                $primaries === []
                    ? SpokenOpening::fallback()
                    : SpokenOpening::primary(1, continuation: $entry['competency_ordinal'] > 1),
                $entry['competency_ordinal'] >= $entry['total_competencies'] ? $finalPhrase : $endPhrase,
            );
        }

        return $this->composer->composeMany($inputs);
    }
}
