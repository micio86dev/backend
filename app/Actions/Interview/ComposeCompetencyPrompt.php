<?php

declare(strict_types=1);

namespace App\Actions\Interview;

use App\DTOs\Conversation\ComposedPrompt;
use App\DTOs\Conversation\ResolvedCompetencyInput;
use App\DTOs\Conversation\SpokenOpening;
use App\Enums\AssessmentType;
use App\Enums\PromptSource;
use App\Exceptions\Conversation\CompositionException;
use App\Exceptions\Conversation\PromptTemplateUnresolvableException;
use App\Exceptions\Scoring\AnchorTranslationMissingException;
use App\Models\Competency;
use App\Models\Project;
use App\Models\Role;
use App\Services\Conversation\PromptSetResolver;
use App\Services\Conversation\SystemPromptComposer;

/**
 * ComposeCompetencyPrompt — the system prompt for ONE competency.
 *
 * A MOVE of `InterviewController::composePromptForCompetency()` (tavus-single-
 * session-interview API-03a), with one change of shape and none of behaviour: the
 * three early "not found" exits that used to return a 422 JsonResponse now throw
 * `CompositionException`, which the controller maps to the SAME 422
 * `composition_error`. Every exception the composer and the stored-set resolver
 * throw propagates unchanged, so the 500-vs-422 rule is the caller's, as before:
 *
 *   - `CompositionException` / `AnchorTranslationMissingException` /
 *     `PromptTemplateUnresolvableException` are composition problems (a 422).
 *   - Anything else (a database outage while resolving the stored set) is a 500
 *     on purpose, and must never silently fall back to the baseline text.
 *
 * The returned `ComposedPrompt` already carries the stored set it was composed
 * from (`stampRef()`; null for the `baseline` source, which reads no table).
 *
 * The caller resolves the catalogue revision, the competency row, the authored
 * primaries, the spoken opening and the advance phrase ONCE per request and
 * passes them in, so the composed prompt and everything else /start does agree
 * on the same turn.
 */
final class ComposeCompetencyPrompt
{
    public function __construct(
        private readonly SystemPromptComposer $composer,
        private readonly PromptSetResolver $promptSets,
    ) {}

    /**
     * @param  list<string>  $primaryQuestions
     *
     * @throws CompositionException
     * @throws AnchorTranslationMissingException
     * @throws PromptTemplateUnresolvableException
     */
    public function handle(
        Project $project,
        AssessmentType $assessmentType,
        string $competencyCode,
        ?int $revisionId,
        ?Competency $competency,
        array $primaryQuestions,
        int $followUpBudget,
        SpokenOpening $spokenOpening,
        ?string $advancePhrase = null,
    ): ComposedPrompt {
        $input = $this->resolveInput(
            $project,
            $assessmentType,
            $competencyCode,
            $revisionId,
            $competency,
            $primaryQuestions,
            $followUpBudget,
            $spokenOpening,
            $advancePhrase,
        );

        $composed = $this->composer->compose(
            competencyCode: $input->competencyCode,
            roleId: $input->roleId,
            competencyId: $input->competencyId,
            projectLocale: $input->projectLocale,
            followUpBudget: $input->followUpBudget,
            nudgeMinChars: $input->nudgeMinChars,
            // The sentence the avatar must SPEAK to end its turn. Without it the
            // prompt told it to utter a placeholder it had never been given, so no
            // question ever ended by itself.
            advancePhrase: $input->advancePhrase,
            // What the OPERATOR wrote for this competency. These ARE the primaries,
            // never additive to `$followUpBudget` — see SystemPromptComposer.
            primaryQuestions: $input->primaryQuestions,
            spokenOpening: $input->spokenOpening,
            revisionId: $input->revisionId,
            templates: $input->templates,
            // At most one body, already chosen (role-specific over role-less) and
            // checked against the override contract by the resolver. The `baseline`
            // source reads no table, so it has none.
            override: $input->override,
        );

        // `version` stays the configured string (the client sees it); the set the
        // text came from travels beside it for the durable stamp.
        return $input->promptSetRef === null
            ? $composed
            : new ComposedPrompt($composed->text, $composed->version, $input->promptSetRef);
    }

    /**
     * Everything `compose()` needs for ONE competency, resolved: the role, the stored set
     * and its override. Shared by the single-competency path ({@see self::handle()}) and the
     * multi-competency plan (`ComposeConversationPlan`), so both resolve one way.
     *
     * @param  list<string>  $primaryQuestions
     *
     * @throws CompositionException
     * @throws PromptTemplateUnresolvableException
     */
    public function resolveInput(
        Project $project,
        AssessmentType $assessmentType,
        string $competencyCode,
        ?int $revisionId,
        ?Competency $competency,
        array $primaryQuestions,
        int $followUpBudget,
        SpokenOpening $spokenOpening,
        ?string $advancePhrase = null,
    ): ResolvedCompetencyInput {
        if ($revisionId === null) {
            // The project's own pin did not resolve (`CatalogueRevisionResolver::
            // tryForProject()`). Treated identically to "role/competency not found
            // in catalog" below. Never falls back to "latest published": that would
            // pin an already-created project onto whatever revision happens to be
            // newest right now (CLAUDE.md ruling 3).
            throw new CompositionException;
        }

        // Resolve the role from the assessment type, never from whether role_code
        // happens to be null. `standard` resolves project.role_code scoped to the
        // project's OWN pinned revision — a bare `where('code', ...)` would resolve
        // whichever of the baseline/draft pair Postgres happens to return first once
        // a draft sharing this code exists. `potential` carries no role by rule and
        // composes against the role-less BARS rows (`role_id IS NULL`). The match
        // has NO default arm on purpose: a new AssessmentType case must fail here,
        // loudly, instead of silently composing against the wrong indicator set.
        $roleId = match ($assessmentType) {
            AssessmentType::Standard => Role::where('code', $project->role_code)
                ->where('revision_id', $revisionId)
                ->first()?->id,
            AssessmentType::Potential => null,
        };

        if ($assessmentType === AssessmentType::Standard && $roleId === null) {
            // role_code set on project but not found in catalog → composition failure.
            throw new CompositionException;
        }

        if ($competency === null) {
            // Resolved by the caller from the SAME (code, revisionId) pair;
            // null means "not found in catalog" there too.
            throw new CompositionException;
        }

        // The ACTIVE stored prompt set (db-driven-conversation-prompts, N-9). A
        // missing set, an ambiguous or tampered one, a missing locale and an
        // invalid source flag all throw from here: no fallback to other text.
        // `baseline` is the break-glass and reads no table.
        $resolved = PromptSource::configured() === PromptSource::Db
            ? $this->promptSets->resolveActive(
                $project->language,
                $competencyCode,
                $assessmentType === AssessmentType::Standard ? $project->role_code : null,
            )
            : null;

        return new ResolvedCompetencyInput(
            competencyCode: $competencyCode,
            roleId: $roleId,
            competencyId: $competency->id,
            projectLocale: $project->language,
            followUpBudget: $followUpBudget,
            nudgeMinChars: $project->nudge_min_chars,
            advancePhrase: $advancePhrase,
            primaryQuestions: $primaryQuestions,
            spokenOpening: $spokenOpening,
            revisionId: $revisionId,
            templates: $resolved?->templates,
            override: $resolved?->override,
            promptSetRef: $resolved?->stampRef(),
        );
    }
}
