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
 * Template sections. ONLY section 2's content is in the project language — the indicator
 * text and anchors are translated, everything else is English interviewer instruction.
 * (This docblock previously claimed all sections were localised. They never were, and a
 * reader who believed it would localise one section and not its neighbours.)
 *   1. Role/interview-style instructions.
 *   2. Coverage topics — ordered, role-scoped BARS indicator text (internal; not revealed
 *      verbatim). The ONLY localised section.
 *   3. STAR coverage protocol + the same-episode constraint (star-interviewer-protocol).
 *      Placed before the follow-up rules: it says what a follow-up is FOR, and a budget
 *      stated before any notion of what to spend it on is a number without a purpose.
 *   4. Follow-up budget: "ask at most N follow-up questions" (ratified N=4, from config;
 *      a per-project override follows as `project-followup-budget`).
 *   5. Nudge: if answer < nudge_min_chars, re-prompt once — does NOT consume a follow-up slot.
 *      Omitted when nudge_min_chars is null or 0.
 *   6. Advance rule: speak end_phrase only after (coverage OR budget exhausted) AND the
 *      effective minimum question count is reached.
 *
 * i18n hard-fail (M-2): reuses AnchorTranslationMissingException semantics from
 * PromptBuilder.php:72-73. A missing translation for ANY of the four translatable fields
 * (text, anchor_5, anchor_3, anchor_1) on ANY indicator throws immediately — no silent
 * English fallback, no partial-language prompt.
 *
 * REQ: SystemPromptComposer (C8 Phase 3 — tasks 3.9 + 3.10)
 * REQ: SA-02 (follow-up budget), SA-03 (nudge), i18n hard-fail (M-2)
 * REQ: STAR protocol, same-episode constraint, clamped minimum (star-interviewer-protocol)
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
     * @param  int  $budget  Maximum follow-up questions (ratified default = 4).
     * @param  int|null  $nudgeMinChars  Min chars for a "sufficient" answer; null = nudge disabled.
     * @param  int|null  $minQuestions  Minimum questions before closing; null = platform default.
     *                                  CLAMPED to what the budget permits — see effectiveMinimum().
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
        ?string $advancePhrase = null,
        ?int $minQuestions = null,
    ): ComposedPrompt {
        $indicators = $this->loader->forRoleCompetency($roleId, $competencyId);

        if ($indicators->isEmpty()) {
            throw new CompositionException(
                "SystemPromptComposer: no BARS indicators found for role [{$roleId}] and competency [{$competencyCode}]. "
                .'Cannot compose a prompt without indicators — a prompt-less session would silently lose all adaptivity.',
            );
        }

        $effectiveMinimum = $this->effectiveMinimum($minQuestions, $budget);

        $coverageSection = $this->buildCoverageSection($competencyCode, $indicators, $projectLocale);
        $starSection = $this->buildStarSection();
        $budgetSection = $this->buildBudgetSection($budget);
        $nudgeSection = $this->buildNudgeSection($nudgeMinChars);
        $advanceSection = $this->buildAdvanceSection($advancePhrase, $effectiveMinimum);

        $text = $this->assemblePrompt(
            $competencyCode,
            $coverageSection,
            $starSection,
            $budgetSection,
            $nudgeSection,
            $advanceSection,
        );

        $version = (string) config('conversation.prompt_version', 'conv-2026-07-23');

        return new ComposedPrompt(text: $text, version: $version);
    }

    // ─── The clamp ────────────────────────────────────────────────────────────

    /**
     * The minimum question count the prompt may state, CLAMPED to what the
     * budget actually permits (star-interviewer-protocol D-1/D-2).
     *
     * ⚠️ THIS CLAMP IS THE FEATURE'S SAFETY PROPERTY, NOT DEFENSIVE CODING.
     *
     * `buildAdvanceSection()` below records what happens when the avatar cannot
     * satisfy its advance condition: it never speaks the closing phrase,
     * `matchesEndPhrase()` never matches, the competency runs to its session
     * cap, and on HeyGen the session dies with MAX_DURATION_REACHED — which the
     * candidate experiences as an error at the end of a question they answered
     * completely. This system has already shipped that defect once.
     *
     * A minimum question count is, by construction, a NEW way to make that
     * condition unsatisfiable: told to ask at least M questions and at most B
     * follow-ups with M > B + 1, the avatar can never legally close.
     *
     * `min($configured, $budget + 1)` makes that impossible. The `+ 1` is the
     * opening question, which is not a follow-up and consumes no budget. Because
     * the result can never exceed `budget + 1`, BUDGET EXHAUSTION ALWAYS
     * SATISFIES THE MINIMUM, so the "OR the follow-up budget is exhausted"
     * escape hatch in the advance rule stays reachable under every possible
     * configuration. That is the entire argument.
     *
     * `max(1, ...)` guards the other end: a configured 0 or negative would state
     * a minimum of zero questions, which reads as permission to close before
     * asking anything.
     *
     * It does NOT throw. A CompositionException at /start is a candidate looking
     * at a broken interview because two operator-supplied numbers disagreed;
     * clamping degrades to the behaviour that shipped before this change, which
     * is the correct direction to fail in.
     */
    private function effectiveMinimum(?int $minQuestions, int $budget): int
    {
        $configured = $minQuestions ?? (int) config('conversation.min_questions', 4);

        return max(1, min($configured, $budget + 1));
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
     * Section 3: the STAR coverage protocol and the same-episode constraint
     * (star-interviewer-protocol D-3).
     *
     * The same-episode constraint lives INSIDE this section rather than in one
     * of its own: it is meaningless except in reference to the episode STAR is
     * describing, and separating them would let a future editor delete one
     * without noticing the other stopped making sense.
     *
     * Action and Result are singled out deliberately. `PromptBuilder`'s
     * EVALUATION_STANDARDS refuses to award a 4 or 5 without concrete personal
     * actions and a measurable outcome — so the interviewer must ASK for exactly
     * what the evaluator is REQUIRED to find. Those two prompts are a matched
     * pair; an edit to either should check the other.
     *
     * The rule is stated ONCE. The reference log repeats it three times;
     * repetition competes with the other rules in this prompt for the model's
     * attention, and if once proves insufficient in a live interview the fix is
     * repetition WITH EVIDENCE, not on the reference's authority.
     */
    private function buildStarSection(): string
    {
        return <<<'STAR'
The candidate will describe ONE episode from their own past. Your job is to make that
single episode complete enough to assess. After EVERY answer, work out which of these
five is least covered for the episode under discussion, and make your next question
close that gap:
  S — Situation: the concrete circumstances, and when and where it happened.
  T — Task: what the candidate was responsible for delivering.
  C — Context: the constraints, pressures and people involved.
  A — Action: what the candidate personally did — the specific steps THEY took,
      not what the team did.
  R — Result: how it ended, with a measurable outcome wherever one exists.

Action and Result are the two candidates most often leave implicit, and they are
exactly what the assessment demands: an answer with no concrete actions the candidate
personally did, and no measurable outcome, cannot score well however articulate it is.
Ask for them explicitly rather than hoping they arrive.

If an element genuinely does not apply to this episode, or the candidate says they
cannot recall it, treat it as covered and do not ask about it again.

STAY ON ONE EPISODE. Every follow-up must deepen the SAME episode the candidate has
already begun describing. Do NOT ask for a second or different example. The single
exception: if the episode turns out to contain no assessable behaviour at all, you may
ask for a different one.
STAR;
    }

    /**
     * Section 4: Follow-up budget instruction (SA-02).
     *
     * REQ: SA-02 — at most N follow-up questions per competency. N=4 RATIFIED
     * 2026-08-25 (was 2, provisional since C8). A per-project override arrives
     * with `project-followup-budget`; this method reads whatever it is handed.
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
     * Section 6: Advance rule — speak end_phrase only after
     * (coverage OR budget exhausted) AND the effective minimum is reached.
     *
     * REQ: R-5 advance signal, star-interviewer-protocol D-4.
     */
    private function buildAdvanceSection(?string $advancePhrase, int $minQuestions): string
    {
        // The phrase must be QUOTED here, verbatim. It used to say "speak
        // end_phrase" and never said what end_phrase was — the avatar was told
        // to utter a placeholder whose value it had never been given. It never
        // said the sentence, `matchesEndPhrase()` never matched, and every
        // competency ran to its cap. On HeyGen the session hit
        // MAX_DURATION_REACHED first and died, which the candidate saw as an
        // error at the end of a question they had answered completely.
        //
        // This string and the one the client matches against MUST be the same:
        // both come from `interview.{end,final}_phrase` in the project's locale.
        // If they ever diverge, completion stops firing silently.
        // The minimum is a CONJUNCT, and it is safe because effectiveMinimum()
        // clamped it to at most `budget + 1` — so "budget exhausted" can never
        // be blocked by a minimum the avatar has not yet reached.
        $floor = $minQuestions === 1
            ? 'you have asked at least 1 question in this competency'
            : "you have asked at least {$minQuestions} questions in this competency";

        if ($advancePhrase === null || trim($advancePhrase) === '') {
            return 'Speak the closing phrase ONLY when all coverage topics have been addressed '
                .'OR the follow-up budget is exhausted, AND '.$floor.'. '
                .'Do NOT close after the first answer.';
        }

        return 'When all coverage topics have been addressed OR the follow-up budget is '
            .'exhausted, AND '.$floor.', you MUST end your turn by saying this sentence '
            .'exactly, word for word, as your final sentence: "'.$advancePhrase.'" '
            .'Say it verbatim — do not paraphrase, translate or add to it. '
            .'Do NOT say it after the first answer, and never say it before the coverage '
            .'topics are addressed.';
    }

    /**
     * Assemble the full prompt from section strings.
     */
    private function assemblePrompt(
        string $competencyCode,
        string $coverageSection,
        string $starSection,
        string $budgetSection,
        string $nudgeSection,
        string $advanceSection,
    ): string {
        $parts = [
            'You are an adaptive interviewer conducting a BARS-based competency assessment '
            ."for the [{$competencyCode}] competency.",
            '',
            // The avatar has ALREADY spoken an opening line that asks for the
            // episode (lang/{locale}/interview.php `opening.*`), delivered by the
            // provider as its greeting field before this prompt ever runs. The
            // model must not re-ask it: the candidate's first utterance IS the
            // answer to a question they have already heard.
            'OPENING: you have ALREADY greeted the candidate and ALREADY asked them to describe '
            .'a specific episode. Do NOT open by asking for one again. Treat their next reply as '
            .'the episode and begin probing it.',
            '',
            'COVERAGE TOPICS (evaluate these behavioral indicators — do not reveal them verbatim):',
            $coverageSection,
            '',
            'STAR COVERAGE PROTOCOL — how to conduct this competency:',
            $starSection,
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
