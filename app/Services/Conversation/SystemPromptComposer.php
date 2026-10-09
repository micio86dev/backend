<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use App\DTOs\Conversation\ComposedPrompt;
use App\DTOs\Conversation\PromptTemplateSet;
use App\DTOs\Conversation\SpokenOpening;
use App\Enums\PromptFragmentKey;
use App\Exceptions\Conversation\CompositionException;
use App\Exceptions\Scoring\AnchorTranslationMissingException;
use App\Models\BarsIndicator;
use App\Support\Conversation\BaselinePromptFragments;
use App\Support\Conversation\PromptFragmentContract;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

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
 *   1. Role/interview-style instructions, then the OPENING paragraph: what the
 *      provider already spoke before this prompt runs (see SpokenOpening).
 *   2. Coverage topics — ordered, role-scoped BARS indicator text (internal; not revealed
 *      verbatim). The ONLY localised section.
 *   2b. Override (optional): the operator's guidance for this competency, one section headed
 *      by `label.override`, rendered only when one applies (db-driven-conversation-prompts).
 *   3. STAR coverage protocol + the same-episode constraint (star-interviewer-protocol).
 *      Placed before the follow-up rules: it says what a follow-up is FOR, and a budget
 *      stated before any notion of what to spend it on is a number without a purpose.
 *   4. Follow-up budget: "ask at most N follow-up questions" (ratified N=4, from config;
 *      a per-project override follows as `project-followup-budget`). ON TOP of the
 *      primary questions, never merged with their count (D7).
 *   5. Nudge: a short answer to a substantive question may be re-prompted once — does NOT
 *      consume a follow-up slot. A soft rule; omitted when nudge_min_chars is null or 0.
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
     * @param  int|null  $roleId  Role primary key — MUST match the project's role. `null`
     *                            is the role-less lookup used by `potential` assessments:
     *                            forwarded unchanged to `BarsIndicatorLoader::forRoleCompetency()`,
     *                            which then selects only the rows with no role.
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
     *                                          generative latitude is follow-ups.
     * @param  SpokenOpening|null  $spokenOpening  What `OpeningTextComposer` spoke as this
     *                                             request's opening. Null means the fresh-start
     *                                             default: primary 1, or the fallback episode
     *                                             question when there are no primaries.
     * @param  int|null  $revisionId  The catalogue revision `$roleId`/`$competencyId` were
     *                                resolved against — framework-catalogue-authoring PR3b,
     *                                H1. Threaded straight through to `BarsIndicatorLoader`;
     *                                appended LAST so every existing positional call site
     *                                keeps working unmodified. `InterviewController` resolves
     *                                its project's own pinned revision and always passes it;
     *                                omitted, the loader defaults to the latest published
     *                                revision, never "any revision".
     * @param  PromptTemplateSet|null  $templates  The prose fragments the static sections are
     *                                             rendered from (db-driven-conversation-prompts).
     *                                             Appended LAST, like `$revisionId`. Null builds
     *                                             the baseline set for `$projectLocale` from
     *                                             `BaselinePromptFragments`, so every caller that
     *                                             passes nothing gets today's bytes. Branch
     *                                             selection, clamps, joins and numbering stay here.
     * @param  string|null  $override  The operator's guidance for THIS competency
     *                                 (db-driven-conversation-prompts PR9), already chosen by
     *                                 `PromptSetResolver` (role-specific over role-less, never
     *                                 both) and already checked against the override contract
     *                                 there. Appended LAST. Null renders nothing and the output is
     *                                 byte-identical to a call without it; otherwise it renders as
     *                                 ONE section headed by `label.override`, after COVERAGE TOPICS
     *                                 and before the STAR protocol, and moves nothing else. It is
     *                                 appended verbatim AFTER every other section is rendered, so
     *                                 no token in it is ever substituted.
     *
     * @throws CompositionException When no indicators exist for the role+competency pair,
     *                              `$spokenOpening` names a primary the set does not have,
     *                              or a provided `$templates` breaks the placeholder contract.
     * @throws AnchorTranslationMissingException When any indicator field lacks a $projectLocale translation.
     */
    public function compose(
        string $competencyCode,
        ?int $roleId,
        int $competencyId,
        string $projectLocale,
        int $followUpBudget,
        ?int $nudgeMinChars,
        ?string $advancePhrase = null,
        ?int $minQuestions = null,
        array $primaryQuestions = [],
        ?SpokenOpening $spokenOpening = null,
        ?int $revisionId = null,
        ?PromptTemplateSet $templates = null,
        ?string $override = null,
    ): ComposedPrompt {
        if ($templates === null) {
            $templates = BaselinePromptFragments::templateSet($projectLocale);
        } else {
            self::assertTemplatesHonourTheContract($templates);
        }

        $indicators = $this->loader->forRoleCompetency($roleId, $competencyId, $revisionId);

        if ($indicators->isEmpty()) {
            $roleLabel = $roleId === null ? 'none' : (string) $roleId;

            throw new CompositionException(
                "SystemPromptComposer: no BARS indicators found for role [{$roleLabel}] and competency [{$competencyCode}]. "
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
        $spokenOpening = self::resolveSpokenOpening($spokenOpening, $primaryQuestions);

        $coverageSection = $this->buildCoverageSection($competencyCode, $indicators, $projectLocale);
        $starSection = $this->buildStarSection($templates);
        $budgetSection = $this->buildBudgetSection($templates, $followUpBudget);
        $nudgeSection = $this->buildNudgeSection($templates, $nudgeMinChars);
        $advanceSection = $this->buildAdvanceSection($templates, $advancePhrase, $effectiveMinimum, $primaryQuestions !== []);
        $primarySection = $this->buildPrimaryQuestionsSection($templates, $primaryQuestions, $spokenOpening);
        $openingSection = $this->buildOpeningSection($templates, $primaryQuestions, $spokenOpening);

        $text = $this->assemblePrompt(
            $templates,
            $competencyCode,
            $openingSection,
            $coverageSection,
            $starSection,
            $budgetSection,
            $nudgeSection,
            $advanceSection,
            $primarySection,
            $override,
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

    /**
     * Render one fragment, turning the set's refusal into a composition failure.
     *
     * The controller catches `CompositionException` and answers 422; anything
     * else becomes a 500. The message carries the key and the token names
     * `PromptTemplateSet` reports, never a template body.
     *
     * @param  array<string, string|int>  $values
     *
     * @throws CompositionException When the set cannot render the fragment with these values.
     */
    private function render(PromptTemplateSet $templates, PromptFragmentKey $key, array $values = []): string
    {
        try {
            return $templates->render($key, $values);
        } catch (InvalidArgumentException $e) {
            throw new CompositionException(
                "SystemPromptComposer: the fragment [{$key->value}] cannot be rendered: ".$e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * A provided set is checked against the placeholder contract before it is
     * used. `render()` substitutes the tokens it is given and never reads the
     * template, so a template without `{{advance_phrase}}` would silently
     * drop the closing sentence (completion never fires, the provider session
     * dies with MAX_DURATION_REACHED) and an unknown `{{x}}` would reach the
     * model literally. The publish-time guard cannot be the only line of
     * defence: migrations, seeders and raw SQL bypass it.
     *
     * @throws CompositionException With every violation, each naming a key and, where one applies, a token.
     */
    private static function assertTemplatesHonourTheContract(PromptTemplateSet $templates): void
    {
        $contract = new PromptFragmentContract;
        $violations = [];

        foreach (PromptFragmentKey::cases() as $key) {
            $violations = [...$violations, ...$contract->violations($key, $templates->template($key))];
        }

        if ($violations !== []) {
            throw new CompositionException(
                'SystemPromptComposer: the prompt template set breaks the placeholder contract: '
                .implode('; ', $violations).'.',
            );
        }
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
     * that impossible. There is no separate "+1 for the opening question"
     * term: the opening always speaks one of `$primaryQuestions` (primary 1 on
     * a fresh start, the pending or last one on a resume, where questions
     * asked before the interruption also count), so it is already counted
     * inside `count($primaryQuestions)`. With no primaries the fallback
     * opening is the one question the budget does not cover, which only
     * leaves more room under the minimum. Because the result can never exceed
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
     * It opens with how an episode comes to exist at all. A primary is the
     * operator's question and may not ask for one ("Ciao! Come ti chiami?"),
     * so the follow-ups are where the model leads the candidate to a concrete
     * past episode relevant to the competency. STAR then governs that episode.
     *
     * The same-episode constraint lives INSIDE this section rather than in one
     * of its own: it is meaningless except in reference to the episode STAR is
     * describing, and separating them would let a future editor delete one
     * without noticing the other stopped making sense. It binds follow-ups
     * only: a later primary may change the subject, and asking it is required.
     *
     * Action and Result are singled out deliberately. `PromptBuilder`'s
     * EVALUATION_STANDARDS refuses to award a 4 or 5 without concrete personal
     * actions and a measurable outcome — so the interviewer must ASK for exactly
     * what the evaluator is REQUIRED to find. Those two prompts are a matched
     * pair; an edit to either should check the other.
     *
     * The same-episode rule is stated ONCE: repetition competes with the other
     * rules in this prompt for the model's attention.
     */
    private function buildStarSection(PromptTemplateSet $templates): string
    {
        return $this->render($templates, PromptFragmentKey::Star);
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
    private function buildBudgetSection(PromptTemplateSet $templates, int $budget): string
    {
        return $this->render($templates, PromptFragmentKey::Budget, ['budget' => $budget]);
    }

    /**
     * Section 5: Nudge instruction (SA-03). Omitted when nudge_min_chars is null or 0.
     *
     * REQ: SA-03 — a short answer may be re-prompted once without consuming a follow-up slot.
     *
     * Scoped to substantive questions and worded as a permission, not an
     * obligation: a primary may be "what's your name?", and a hard
     * character threshold would trap the model re-prompting a complete
     * one-word answer.
     */
    private function buildNudgeSection(PromptTemplateSet $templates, ?int $nudgeMinChars): string
    {
        if ($nudgeMinChars === null || $nudgeMinChars <= 0) {
            return '';
        }

        return $this->render($templates, PromptFragmentKey::Nudge, ['nudge_min_chars' => $nudgeMinChars]);
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
     * The opening the prompt describes: the caller's, or the fresh-start
     * default when none was given.
     *
     * @param  list<string>  $primaryQuestions
     *
     * @throws CompositionException When the opening names a primary the set does not have.
     */
    private static function resolveSpokenOpening(?SpokenOpening $opening, array $primaryQuestions): SpokenOpening
    {
        if ($primaryQuestions === []) {
            return SpokenOpening::fallback($opening !== null && $opening->resumed);
        }

        $opening ??= SpokenOpening::primary(1);

        if ($opening->primaryNumber === null || $opening->primaryNumber > count($primaryQuestions)) {
            throw new CompositionException(
                'SystemPromptComposer: the spoken opening does not name one of the '
                .count($primaryQuestions).' primary questions, so the prompt cannot state what was asked.',
            );
        }

        return $opening;
    }

    /**
     * The OPENING paragraph: what the provider already spoke before this
     * prompt runs, quoted when it was a primary. The model cannot hear its
     * own greeting, so this is the only way it knows what the candidate's
     * next reply is answering.
     *
     * Which fragments apply is decided here (fallback, fresh, resumed, re-ask of an
     * asked primary, and the closing no-greeting clause of a later competency); the words, and the single spaces that join them, are the set's
     * and the composer's respectively.
     *
     * @param  list<string>  $questions
     */
    private function buildOpeningSection(PromptTemplateSet $templates, array $questions, SpokenOpening $opening): string
    {
        $parts = [$this->render($templates, PromptFragmentKey::LabelOpening)];

        if ($opening->resumed) {
            $parts[] = $this->render($templates, PromptFragmentKey::OpeningResumedNotice);
        }

        if ($opening->primaryNumber === null) {
            $parts[] = $this->render($templates, PromptFragmentKey::OpeningFallback);

            return implode(' ', $parts);
        }

        $number = $opening->primaryNumber;
        $quoted = $this->render($templates, PromptFragmentKey::OpeningQuoted, [
            'number' => $number,
            'question' => $questions[$number - 1],
        ]);

        $parts[] = match (true) {
            $opening->isReAskOfAskedPrimary() => $this->render($templates, PromptFragmentKey::OpeningSpokenReaskAll, ['quoted' => $quoted]),
            $opening->resumed => $this->render($templates, PromptFragmentKey::OpeningSpokenResumed, ['quoted' => $quoted]),
            default => $this->render($templates, PromptFragmentKey::OpeningSpokenFresh, ['quoted' => $quoted]),
        };
        $parts[] = $this->render($templates, PromptFragmentKey::OpeningClosing);

        // A later competency starts a NEW provider session whose model has no
        // memory of the welcome. Said last and only when flagged, so every other
        // opening is byte-identical to what it was before the flag existed.
        if ($opening->continuation) {
            $parts[] = $this->render($templates, PromptFragmentKey::OpeningContinuation);
        }

        return implode(' ', $parts);
    }

    /**
     * Section 6: the competency's complete primary-question set — the
     * operator's own `project_questions` rows for this project × competency
     * (framework-catalogue-authoring PR7, D7).
     *
     * WHY THE PROMPT AND NOT ONLY THE SPOKEN OPENING. The opening speaks one
     * primary; the rest must be asked by the model, in order and verbatim,
     * and it can only do that if it has the list.
     *
     * COMPLETE, NOT ADDITIVE, AND NOT A SUGGESTION. This is the FULL primary
     * set for the competency (D7). The model may not introduce, substitute,
     * reorder or reword a primary; its generative latitude is follow-ups,
     * which may probe an answer or lead from it toward a concrete episode.
     *
     * The numbered list always lists every primary. The progress sentence
     * says which primaries are already asked and what comes next, and only
     * ever names "primary question N" for an N that exists: with one
     * primary, or once the last one has been spoken, it says that every
     * primary is asked and the rest of the competency is follow-ups.
     *
     * With no primaries (reachable only while the interviewability gate is
     * off) there is no list; the section says the fallback opening was the
     * competency's only question.
     *
     * The words of every sentence are the set's; which sentences apply, the
     * counts they name and the `N. question` numbering are decided here.
     *
     * @param  list<string>  $questions
     */
    private function buildPrimaryQuestionsSection(PromptTemplateSet $templates, array $questions, SpokenOpening $opening): string
    {
        if ($questions === []) {
            return $this->render($templates, PromptFragmentKey::PrimaryNone);
        }

        $total = count($questions);
        $spoken = (int) $opening->primaryNumber;

        $lines = [$this->render($templates, PromptFragmentKey::PrimaryIntro)];

        if ($opening->resumed && $opening->primariesAskedBefore > 0 && ! $opening->isReAskOfAskedPrimary()) {
            $lines[] = $opening->primariesAskedBefore === 1
                ? $this->render($templates, PromptFragmentKey::PrimaryAskedBeforeOne)
                : $this->render($templates, PromptFragmentKey::PrimaryAskedBeforeMany, ['count' => $opening->primariesAskedBefore]);
        }

        $lines[] = match (true) {
            $opening->isReAskOfAskedPrimary() => $this->render($templates, PromptFragmentKey::PrimaryProgressAllAsked),
            $spoken >= $total => $this->render($templates, PromptFragmentKey::PrimaryProgressLast, ['spoken' => $spoken]),
            default => $this->render($templates, PromptFragmentKey::PrimaryProgressNext, ['spoken' => $spoken, 'next' => $spoken + 1]),
        };

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
    private function buildAdvanceSection(PromptTemplateSet $templates, ?string $advancePhrase, int $minQuestions, bool $hasPrimaries): string
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
        //
        // Every clause must stay satisfiable together: "every primary asked"
        // is always reachable because the model asks them, and budget
        // exhaustion satisfies the clamped minimum. Nothing here may forbid
        // closing once those hold — not "never before coverage", not "never
        // after the first answer" — or a one-primary, zero-budget competency
        // could never close.
        $floor = $minQuestions === 1
            ? $this->render($templates, PromptFragmentKey::AdvanceFloorOne)
            : $this->render($templates, PromptFragmentKey::AdvanceFloorMany, ['min_questions' => $minQuestions]);

        if ($hasPrimaries) {
            $floor = $this->render($templates, PromptFragmentKey::AdvanceFloorWithPrimaries, ['floor' => $floor]);
        }

        // A phrase with nothing speakable (including non-ASCII whitespace such as NBSP or a
        // zero-width space) takes the no-phrase branch, the same predicate PromptTemplateSet applies.
        if ($advancePhrase === null || trim($advancePhrase) === '' || preg_replace('/[\s\x{200B}]+/u', '', $advancePhrase) === '') {
            return $this->render($templates, PromptFragmentKey::AdvanceWithoutPhrase, ['floor' => $floor]);
        }

        return $this->render($templates, PromptFragmentKey::AdvanceWithPhrase, ['floor' => $floor, 'advance_phrase' => $advancePhrase]);
    }

    /**
     * Assemble the full prompt from section strings.
     */
    private function assemblePrompt(
        PromptTemplateSet $templates,
        string $competencyCode,
        string $openingSection,
        string $coverageSection,
        string $starSection,
        string $budgetSection,
        string $nudgeSection,
        string $advanceSection,
        string $primarySection = '',
        ?string $override = null,
    ): string {
        $parts = [
            $this->render($templates, PromptFragmentKey::Header, ['competency_code' => $competencyCode]),
            '',
            $openingSection,
            '',
            $this->render($templates, PromptFragmentKey::LabelCoverage),
            $coverageSection,
        ];

        // After COVERAGE TOPICS and before the STAR protocol (design N-12): the
        // guidance is about what to look for in this competency, so it belongs
        // beside the indicators, and it stays clear of the ADVANCE RULE that
        // decides when the competency ends.
        // A blank body is no override: the contract refuses one at publish and at
        // resolve time (the same blank definition), and a bare heading would only
        // tell the model there is guidance when there is none.
        if ($override !== null && preg_replace('/[\s\x{200B}]+/u', '', $override) !== '') {
            $parts[] = '';
            $parts[] = $this->render($templates, PromptFragmentKey::LabelOverride);
            $parts[] = $override;
        }

        array_push(
            $parts,
            '',
            $this->render($templates, PromptFragmentKey::LabelStar),
            $starSection,
            '',
            $this->render($templates, PromptFragmentKey::LabelFollowUp),
            $budgetSection,
        );

        if ($nudgeSection !== '') {
            $parts[] = '';
            $parts[] = $this->render($templates, PromptFragmentKey::LabelNudge);
            $parts[] = $nudgeSection;
        }

        // BEFORE the advance rule, and that placement is the point: the advance
        // rule tells the model when it may END the competency, and a question it
        // has not been told about yet cannot be one it waits to ask.
        if ($primarySection !== '') {
            $parts[] = '';
            $parts[] = $this->render($templates, PromptFragmentKey::LabelPrimary);
            $parts[] = $primarySection;
        }

        $parts[] = '';
        $parts[] = $this->render($templates, PromptFragmentKey::LabelAdvance);
        $parts[] = $advanceSection;

        return implode("\n", $parts);
    }
}
