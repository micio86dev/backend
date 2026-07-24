<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use App\DTOs\Conversation\ComposedPrompt;
use App\Exceptions\Conversation\CompositionException;
use App\Exceptions\Scoring\AnchorTranslationMissingException;
use App\Models\BarsIndicator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Composes a deterministic, versioned system prompt for the avatar at /start time.
 *
 * Pure function — no LLM call, no HTTP, no time/random/IO. Given identical inputs,
 * produces identical output. This is the correctness invariant; tests verify it.
 *
 * Template sections (all in the project language):
 *   1. Role/interview-style instructions.
 *   2. Coverage topics — ordered, role-scoped BARS indicator text (internal; not revealed verbatim).
 *   3. Follow-up budget: "ask at most N follow-up questions" (OQ-1 N=2, from config).
 *   4. Nudge: if answer < nudge_min_chars, re-prompt once — does NOT consume a follow-up slot
 *      (OQ-3). Omitted when nudge_min_chars is null or 0.
 *   5. Advance rule: speak end_phrase only after coverage/budget exhausted.
 *
 * i18n hard-fail (M-2): reuses AnchorTranslationMissingException semantics from
 * PromptBuilder.php:72-73. A missing translation for ANY of the four translatable fields
 * (text, anchor_5, anchor_3, anchor_1) on ANY indicator throws immediately — no silent
 * English fallback, no partial-language prompt.
 *
 * REQ: SystemPromptComposer (C8 Phase 3 — tasks 3.9 + 3.10)
 * REQ: SA-02 (follow-up budget), SA-03 (nudge), i18n hard-fail (M-2)
 */
final class SystemPromptComposer
{
    public function __construct(
        private readonly BarsIndicatorLoader $loader,
    ) {}

    /**
     * Compose a system prompt for a single competency evaluation.
     *
     * @param  string  $competencyCode  Competency code (for error messages and section headers).
     * @param  int  $roleId  Role primary key — MUST match the project's role.
     * @param  int  $competencyId  Competency primary key.
     * @param  string  $projectLocale  Project language code ('en' or 'it').
     * @param  int  $budget  Maximum follow-up questions (OQ-1 default = 2).
     * @param  int|null  $nudgeMinChars  Min chars for a "sufficient" answer; null = nudge disabled.
     *
     * @throws CompositionException When no indicators exist for the role+competency pair.
     * @throws AnchorTranslationMissingException When any indicator field lacks a $projectLocale translation.
     */
    public function compose(
        string $competencyCode,
        int $roleId,
        int $competencyId,
        string $projectLocale,
        int $budget,
        ?int $nudgeMinChars,
    ): ComposedPrompt {
        $indicators = $this->loader->forRoleCompetency($roleId, $competencyId);

        if ($indicators->isEmpty()) {
            throw new CompositionException(
                "SystemPromptComposer: no BARS indicators found for role [{$roleId}] and competency [{$competencyCode}]. "
                .'Cannot compose a prompt without indicators — a prompt-less session would silently lose all adaptivity.',
            );
        }

        $coverageSection = $this->buildCoverageSection($competencyCode, $indicators, $projectLocale);
        $budgetSection = $this->buildBudgetSection($budget);
        $nudgeSection = $this->buildNudgeSection($nudgeMinChars);
        $advanceSection = $this->buildAdvanceSection();

        $text = $this->assemblePrompt(
            $competencyCode,
            $coverageSection,
            $budgetSection,
            $nudgeSection,
            $advanceSection,
        );

        $version = (string) config('conversation.prompt_version', 'conv-2026-07-23');

        return new ComposedPrompt(text: $text, version: $version);
    }

    // ─── Private template sections ────────────────────────────────────────────

    /**
     * Section 2: Coverage topics — ordered role-scoped BARS indicator texts with anchors.
     *
     * Applies the M-2 hard-fail: checks ALL four translatable fields per indicator;
     * throws AnchorTranslationMissingException on ANY missing translation.
     *
     * @param  Collection<int, BarsIndicator>  $indicators
     *
     * @throws AnchorTranslationMissingException
     */
    private function buildCoverageSection(
        string $competencyCode,
        Collection $indicators,
        string $locale,
    ): string {
        $lines = [];

        foreach ($indicators as $indicator) {
            // M-2 hard-fail: check all four translatable fields (mirrors PromptBuilder.php:72-73)
            foreach (['text', 'anchor_5', 'anchor_3', 'anchor_1'] as $field) {
                if (! $indicator->hasTranslation($field, $locale)) {
                    throw new AnchorTranslationMissingException($competencyCode, $field, $locale);
                }
            }

            $text = $indicator->getTranslation('text', $locale);
            $anchor5 = $indicator->getTranslation('anchor_5', $locale);
            $anchor3 = $indicator->getTranslation('anchor_3', $locale);
            $anchor1 = $indicator->getTranslation('anchor_1', $locale);

            $lines[] = sprintf(
                "- %s\n  [Excellent: %s | Adequate: %s | Insufficient: %s]",
                $text,
                $anchor5,
                $anchor3,
                $anchor1,
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Section 3: Follow-up budget instruction (SA-02).
     *
     * REQ: SA-02 — at most N follow-up questions per competency (OQ-1 N=2 provisional).
     */
    private function buildBudgetSection(int $budget): string
    {
        return "Ask at most {$budget} follow-up questions per competency. "
            .'Advance (speak end_phrase) only after all coverage topics are addressed OR '
            ."the follow-up budget of {$budget} is exhausted.";
    }

    /**
     * Section 4: Nudge instruction (SA-03). Omitted when nudge_min_chars is null or 0.
     *
     * REQ: SA-03 — if answer < threshold chars, re-prompt once without consuming a follow-up slot.
     */
    private function buildNudgeSection(?int $nudgeMinChars): string
    {
        if ($nudgeMinChars === null || $nudgeMinChars <= 0) {
            return '';
        }

        return "If a candidate's answer is shorter than {$nudgeMinChars} characters, "
            .'re-prompt once asking them to elaborate. '
            .'This re-prompt does NOT consume a follow-up budget slot.';
    }

    /**
     * Section 5: Advance rule — speak end_phrase only after coverage/budget exhaustion.
     *
     * REQ: R-5 advance signal.
     */
    private function buildAdvanceSection(): string
    {
        return 'Speak end_phrase ONLY when all coverage topics have been addressed '
            .'OR the follow-up budget is exhausted. Do NOT speak end_phrase after the first answer.';
    }

    /**
     * Assemble the full prompt from section strings.
     */
    private function assemblePrompt(
        string $competencyCode,
        string $coverageSection,
        string $budgetSection,
        string $nudgeSection,
        string $advanceSection,
    ): string {
        $parts = [
            'You are an adaptive interviewer conducting a BARS-based competency assessment '
            ."for the [{$competencyCode}] competency.",
            '',
            'COVERAGE TOPICS (evaluate these behavioral indicators — do not reveal them verbatim):',
            $coverageSection,
            '',
            'FOLLOW-UP RULES:',
            $budgetSection,
        ];

        if ($nudgeSection !== '') {
            $parts[] = '';
            $parts[] = 'NUDGE RULE:';
            $parts[] = $nudgeSection;
        }

        $parts[] = '';
        $parts[] = 'ADVANCE RULE:';
        $parts[] = $advanceSection;

        return implode("\n", $parts);
    }
}
