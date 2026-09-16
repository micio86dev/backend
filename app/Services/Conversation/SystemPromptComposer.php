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
 *      a per-project override follows as `project-followup-budget`). ON TOP of the
 *      primary questions, never merged with their count (D7).
 *   5. Nudge: if answer < nudge_min_chars, re-prompt once — does NOT consume a follow-up slot.
 *      Omitted when nudge_min_chars is null or 0.
 *   6. Primary questions: the competency's COMPLETE primary set — the operator's
 *      own questions for this project × competency, when any exist. Injected
 *      BEFORE the advance rule and not after — the advance rule is what tells
 *      the model it may close, and a question it has not been told about yet
 *      cannot be one it waits to ask.
 *   7. Advance rule: speak end_phrase only after (coverage OR budget exhausted) AND the
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
     * @param  int  $followUpBudget  Maximum follow-up questions (ratified default = 4).
     *                               Fed to the budget section RAW — never inflated by the
     *                               primary-question count (framework-catalogue-authoring
     *                               PR7, D7). Authored questions ARE the primaries, not an
     *                               addition on top of this budget.
     * @param  int|null  $nudgeMinChars  Min chars for a "sufficient" answer; null = nudge disabled.
     * @param  string|null  $advancePhrase  The sentence the avatar must SPEAK to end its
     *                                      turn. Its absence is what previously killed
     *                                      HeyGen sessions with MAX_DURATION_REACHED —
     *                                      see buildAdvanceSection().
     * @param  int|null  $minQuestions  Minimum questions before closing; null = platform default.
     *                                  CLAMPED to what primaries + budget permit — see effectiveMinimum().
     * @param  list<string>  $primaryQuestions  The competency's COMPLETE primary-question
     *                                          set — the project's own `project_questions`
     *                                          rows, in the order they must be asked. Not
     *                                          additive to `$followUpBudget`: these questions
     *                                          ARE the primaries (D7), and the model's only
     *                                          generative latitude is follow-ups on one of them.
     * @param  bool  $openingSpokeFirstPrimary  True when `OpeningTextComposer` already spoke
     *                                          `$primaryQuestions[0]` as the opening greeting
     *                                          for this request (every variant except `resume`,
     *                                          and only when at least one primary exists). The
     *                                          primary-questions section then tells the model
     *                                          to continue from primary 2 rather than leaving it
     *                                          to infer that primary 1 was already asked.
     * @param  int|null  $revisionId  The catalogue revision `$roleId`/`$competencyId` were
     *                                resolved against — framework-catalogue-authoring PR3b,
     *                                H1. Threaded straight through to `BarsIndicatorLoader`;
     *                                appended LAST so every existing positional call site
     *                                keeps working unmodified. `InterviewController` resolves
     *                                its project's own pinned revision and always passes it;
     *                                omitted, the loader defaults to the latest published
     *                                revision, never "any revision".
     *
     * @throws CompositionException When no indicators exist for the role+competency pair.
     * @throws AnchorTranslationMissingException When any indicator field lacks a $projectLocale translation.
     */
    public function compose(
        string $competencyCode,
        int $roleId,
        int $competencyId,
        string $projectLocale,
        int $followUpBudget,
        ?int $nudgeMinChars,
        ?string $advancePhrase = null,
        ?int $minQuestions = null,
        array $primaryQuestions = [],
        bool $openingSpokeFirstPrimary = false,
        ?int $revisionId = null,
    ): ComposedPrompt {
        $indicators = $this->loader->forRoleCompetency($roleId, $competencyId, $revisionId);

        if ($indicators->isEmpty()) {
            throw new CompositionException(
                "SystemPromptComposer: no BARS indicators found for role [{$roleId}] and competency [{$competencyCode}]. "
                .'Cannot compose a prompt without indicators — a prompt-less session would silently lose all adaptivity.',
            );
        }

        // Normalised ONCE, here, so the count that clamps the minimum and the
        // list that gets injected into the prompt are the same list. The
        // declared contract is `list<string>`, which permits blanks the
        // section would drop — counting before dropping them would inflate
        // the minimum by questions nobody asks.
        $primaryQuestions = self::normalizePrimaryQuestions($primaryQuestions);

        // NEVER added to $followUpBudget (D7 reversal). Authored questions ARE
        // the primaries — the complete, ratified question set for this
        // competency — not a model-driven allotment on top of them. Folding
        // their count into the budget used to let the total silently double-
        // count a question that was never a follow-up in the first place; see
        // buildBudgetSection() and buildPrimaryQuestionsSection() below, which
        // are now fed independently rather than through one merged number.
        $effectiveMinimum = $this->effectiveMinimum($minQuestions, count($primaryQuestions), $followUpBudget);

        $coverageSection = $this->buildCoverageSection($competencyCode, $indicators, $projectLocale);
        $starSection = $this->buildStarSection();
        $budgetSection = $this->buildBudgetSection($followUpBudget);
        $nudgeSection = $this->buildNudgeSection($nudgeMinChars);
        $advanceSection = $this->buildAdvanceSection($advancePhrase, $effectiveMinimum);
        $primarySection = $this->buildPrimaryQuestionsSection($primaryQuestions, $openingSpokeFirstPrimary);

        $text = $this->assemblePrompt(
            $competencyCode,
            $coverageSection,
            $starSection,
            $budgetSection,
            $nudgeSection,
            $advanceSection,
            $primarySection,
        );

        // No fallback, and a BLANK is refused rather than stamped. The literal
        // here used to read 'conv-2026-07-23', two bumps behind the config
        // default, so on a missing-key path the two composers would stamp
        // DIFFERENT prompt_version values onto the same interview. Removing the
        // literal was not enough on its own: `(string) null` is `''`, which
        // stamps an EMPTY version just as silently. That string is what C9 uses
        // for provenance — an interview nobody can trace back to a prompt is the
        // failure this key exists to prevent, so it fails at composition instead.
        $version = self::promptVersion();

        return new ComposedPrompt(text: $text, version: $version);
    }

    // ─── The clamp ────────────────────────────────────────────────────────────

    /**
     * The minimum question count the prompt may state, CLAMPED to what the
     * primaries plus the follow-up budget actually permit
     * (star-interviewer-protocol D-1/D-2; arithmetic revised by
     * framework-catalogue-authoring PR7, D7).
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
     * condition unsatisfiable: told to ask at least M questions and at most
     * `count($primaryQuestions) + $followUpBudget`, the avatar can never
     * legally close.
     *
     * `min($configured, count($primaryQuestions) + $followUpBudget)` makes
     * that impossible. There is no separate "+1 for the opening question" term
     * any more: the opening question IS `$primaryQuestions[0]` (D7 — the two
     * composers resolve it from the same array), so it is already counted
     * inside `count($primaryQuestions)` rather than sitting outside both
     * numbers. Because the result can never exceed
     * `count($primaryQuestions) + $followUpBudget`, BUDGET EXHAUSTION ALWAYS
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
    private function effectiveMinimum(?int $minQuestions, int $primaryCount, int $followUpBudget): int
    {
        $configured = $minQuestions ?? (int) config('conversation.min_questions', 4);

        return max(1, min($configured, $primaryCount + $followUpBudget));
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
     *
     * The BUDGET ONLY. This section used to restate the advance rule as
     * "Advance (speak end_phrase) only after ..." — the very literal
     * buildAdvanceSection() documents as the bug it was repaired for: an
     * unbound `end_phrase` token the avatar was told to utter without ever
     * being given its value. It also stated the condition as
     * `coverage OR budget`, dropping the minimum-questions conjunct that
     * section 7 requires, so the two sections of one prompt disagreed about
     * when the interview may close, and the weaker one read as permission.
     * The advance rule belongs in exactly one place, and it has one.
     */
    private function buildBudgetSection(int $budget): string
    {
        return "Ask at most {$budget} follow-up questions per competency.";
    }

    /**
     * Section 5: Nudge instruction (SA-03). Omitted when nudge_min_chars is null or 0.
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
     * The conversation prompt version, refused when blank.
     *
     * @throws CompositionException When conversation.prompt_version is unset or empty.
     */
    private static function promptVersion(): string
    {
        $version = trim((string) config('conversation.prompt_version'));

        if ($version === '') {
            throw new CompositionException(
                'conversation.prompt_version is not configured. Every composed prompt is '
                .'stamped with it, and an evaluation carrying a blank version cannot be '
                .'traced back to the prompt that produced it.',
            );
        }

        return $version;
    }

    /**
     * Trim the primary questions and drop the blanks.
     *
     * Called once in compose(), before anything reads the list — the count feeds
     * effectiveMinimum() and the same list feeds the prompt section, and those
     * two must not be able to disagree.
     *
     * @param  list<string>  $questions
     * @return list<string>
     */
    private static function normalizePrimaryQuestions(array $questions): array
    {
        return array_values(array_filter(
            array_map(static fn (string $question): string => trim($question), $questions),
            static fn (string $question): bool => $question !== '',
        ));
    }

    /**
     * Section 6: the competency's complete primary-question set — the
     * operator's own `project_questions` rows for this project × competency
     * (framework-catalogue-authoring PR7, D7).
     *
     * WHY THIS EXISTS. The backoffice has offered a per-competency question
     * editor since C4, and nothing ever read what it saved. `ProjectQuestion`
     * was reachable only from its own controller, request and resource — no
     * part of the interview touched it — so an operator could write the exact
     * question they needed asked, watch it persist, and then listen to the
     * avatar improvise something else entirely. The feature was write-only.
     *
     * WHY THE PROMPT AND NOT THE SPOKEN OPENING. The opening greets and hands
     * over; what to ASK is an instruction to the interviewer. Recited as an
     * opening, the avatar would say the question and then have no idea it was
     * supposed to have asked it — it would probe as if the exchange had not
     * happened, and the operator's question would be a line of narration.
     *
     * COMPLETE, NOT ADDITIVE, AND NOT A SUGGESTION. This is the FULL primary
     * set for the competency, not a mandatory add-on layered over a separate
     * model-driven budget (D7 — the additive arithmetic this replaces is
     * deleted, not adjusted). The model may not introduce, substitute,
     * reorder or reword a primary of its own; its only generative latitude is
     * follow-ups on a primary already asked. "Topics you might explore" would
     * yield an interview that sometimes asks them, which from the operator's
     * side is indistinguishable from the write-only bug this section fixes.
     *
     * `$openingSpokeFirstPrimary` states a fact rather than removing an item:
     * the numbered list below always lists every primary, because it is the
     * transcript-facing "complete set" record; when true, an extra sentence
     * tells the model primary 1 was already spoken as the opening greeting
     * and it must continue from primary 2, rather than leaving the model to
     * infer that from context (D7).
     *
     * @param  list<string>  $questions
     */
    private function buildPrimaryQuestionsSection(array $questions, bool $openingSpokeFirstPrimary): string
    {
        if ($questions === []) {
            return '';
        }

        $lines = [
            'The numbered list below is the COMPLETE set of primary questions for this '
            .'competency, in order. You MUST ask every one of them, phrased exactly as '
            .'written, before you end the competency. You may NOT introduce, substitute, '
            .'reorder or reword a primary question of your own — your only generative '
            .'latitude is follow-up questions on a primary you have already asked. Ask '
            .'them as part of the conversation rather than reading a list, and probe each '
            .'answer with your ordinary follow-up rules.',
        ];

        if ($openingSpokeFirstPrimary) {
            $lines[] = 'Primary question 1 has ALREADY been spoken as your opening greeting '
                .'— do NOT ask it again. Continue from primary question 2.';
        }

        $lines[] = '';

        foreach ($questions as $index => $question) {
            $lines[] = ($index + 1).'. '.$question;
        }

        return implode("\n", $lines);
    }

    /**
     * Section 7: Advance rule — speak end_phrase only after
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
        // clamped it to at most `count(primaryQuestions) + followUpBudget` —
        // so "budget exhausted" can never be blocked by a minimum the avatar
        // has not yet reached.
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
        string $primarySection = '',
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

        // BEFORE the advance rule, and that placement is the point: the advance
        // rule tells the model when it may END the competency, and a question it
        // has not been told about yet cannot be one it waits to ask.
        if ($primarySection !== '') {
            $parts[] = '';
            $parts[] = 'PRIMARY QUESTIONS:';
            $parts[] = $primarySection;
        }

        $parts[] = '';
        $parts[] = 'ADVANCE RULE:';
        $parts[] = $advanceSection;

        return implode("\n", $parts);
    }
}
