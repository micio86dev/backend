<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use App\DTOs\Conversation\ComposedPrompt;
use App\DTOs\Conversation\SpokenOpening;
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
 *   1. Role/interview-style instructions, then the OPENING paragraph: what the
 *      provider already spoke before this prompt runs (see SpokenOpening).
 *   2. Coverage topics — ordered, role-scoped BARS indicator text (internal; not revealed
 *      verbatim). The ONLY localised section.
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
     *
     * @throws CompositionException When no indicators exist for the role+competency pair,
     *                              or `$spokenOpening` names a primary the set does not have.
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
        ?SpokenOpening $spokenOpening = null,
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
        $spokenOpening = self::resolveSpokenOpening($spokenOpening, $primaryQuestions);

        $coverageSection = $this->buildCoverageSection($competencyCode, $indicators, $projectLocale);
        $starSection = $this->buildStarSection();
        $budgetSection = $this->buildBudgetSection($followUpBudget);
        $nudgeSection = $this->buildNudgeSection($nudgeMinChars);
        $advanceSection = $this->buildAdvanceSection($advancePhrase, $effectiveMinimum, $primaryQuestions !== []);
        $primarySection = $this->buildPrimaryQuestionsSection($primaryQuestions, $spokenOpening);
        $openingSection = $this->buildOpeningSection($primaryQuestions, $spokenOpening);

        $text = $this->assemblePrompt(
            $competencyCode,
            $openingSection,
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
    private function buildStarSection(): string
    {
        return <<<'STAR'
This competency is assessed on what the candidate actually did in the past. A primary
question does not always ask for that: it may be a greeting or a general question. When
an answer does not already describe a specific episode from the candidate's own past,
use your follow-ups to lead from that answer to ONE such episode relevant to this
competency.

Once an episode is under discussion, make it complete enough to assess. After EVERY
answer about it, work out which of these five is least covered, and make your next
follow-up close that gap:
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

STAY ON ONE EPISODE. Once the candidate has begun describing an episode, every follow-up
must deepen the SAME episode. Do NOT ask for a second or different example. The single
exception: if the episode turns out to contain no assessable behaviour at all, you may
ask for a different one. This rule governs follow-ups only: a primary question still to
be asked may move to another subject, and you must still ask it.
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
     * REQ: SA-03 — a short answer may be re-prompted once without consuming a follow-up slot.
     *
     * Scoped to substantive questions and worded as a permission, not an
     * obligation: a primary may be "what's your name?", and a hard
     * character threshold would trap the model re-prompting a complete
     * one-word answer.
     */
    private function buildNudgeSection(?int $nudgeMinChars): string
    {
        if ($nudgeMinChars === null || $nudgeMinChars <= 0) {
            return '';
        }

        return 'If the candidate\'s answer to a question that asks them to describe, explain or '
            ."give an example is shorter than {$nudgeMinChars} characters, you may re-prompt once "
            .'asking them to elaborate. This re-prompt does NOT consume a follow-up budget slot. A short '
            .'answer to a simple question — their name, a yes or a no — is complete: accept it '
            .'and move on, and never re-prompt it.';
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
     * @param  list<string>  $questions
     */
    private function buildOpeningSection(array $questions, SpokenOpening $opening): string
    {
        $resumed = $opening->resumed
            ? 'This conversation was interrupted and has just resumed; questions asked before the '
                .'interruption count toward the minimum in the ADVANCE RULE. '
            : '';

        if ($opening->primaryNumber === null) {
            return 'OPENING: '.$resumed.'You have ALREADY spoken your opening line, which asked the '
                .'candidate to describe a specific episode from their work related to this '
                .'competency. Do NOT ask for one again. Treat their next reply as that episode and '
                .'begin probing it.';
        }

        $number = $opening->primaryNumber;
        $quoted = 'primary question '.$number.', word for word: "'.$questions[$number - 1].'"';

        $spoken = match (true) {
            $opening->isReAskOfAskedPrimary() => 'Every primary question was already asked before the '
                .'interruption; your opening line re-asked the last one, '.$quoted.', to restart the '
                .'conversation.',
            $opening->resumed => 'Your opening line re-asked '.$quoted.'.',
            default => 'You have ALREADY spoken your opening line, which was '.$quoted.'.',
        };

        return 'OPENING: '.$resumed.$spoken.' Do NOT ask it again. The candidate\'s next reply is '
            .'their answer to it.';
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
     * @param  list<string>  $questions
     */
    private function buildPrimaryQuestionsSection(array $questions, SpokenOpening $opening): string
    {
        if ($questions === []) {
            return 'This competency has no primary questions: your opening line was its only '
                .'primary question, and everything you ask from here on is a follow-up.';
        }

        $total = count($questions);
        $spoken = (int) $opening->primaryNumber;

        $lines = [
            'The numbered list below is the COMPLETE set of primary questions for this '
            .'competency, in order. Every one of them MUST be asked, phrased exactly as '
            .'written, before you end the competency. You may NOT introduce, substitute, '
            .'reorder or reword a primary question of your own — your only generative '
            .'latitude is follow-up questions. A follow-up may probe the answer just given, '
            .'or lead from it toward one concrete episode from the candidate\'s own past '
            .'that is relevant to this competency. Ask the primaries as part of the '
            .'conversation rather than reading a list.',
        ];

        if ($opening->resumed && $opening->primariesAskedBefore > 0 && ! $opening->isReAskOfAskedPrimary()) {
            $lines[] = $opening->primariesAskedBefore === 1
                ? 'Primary question 1 was asked before the interruption.'
                : 'Primary questions 1-'.$opening->primariesAskedBefore.' were asked before the interruption.';
        }

        $lines[] = match (true) {
            $opening->isReAskOfAskedPrimary() => 'Every primary question has already been asked, so '
                .'everything you ask from here on is a follow-up.',
            $spoken >= $total => 'Primary question '.$spoken.' was your opening line and is the last '
                .'one: every primary question has now been asked, so everything you ask from here '
                .'on is a follow-up.',
            default => 'Primary question '.$spoken.' was your opening line. After the candidate '
                .'answers it, ask follow-ups as needed, then continue with primary question '
                .($spoken + 1).'.',
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
    private function buildAdvanceSection(?string $advancePhrase, int $minQuestions, bool $hasPrimaries): string
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
            ? 'you have asked at least 1 question in this competency'
            : "you have asked at least {$minQuestions} questions in this competency";

        if ($hasPrimaries) {
            $floor = 'every primary question has been asked and '.$floor;
        }

        if ($advancePhrase === null || trim($advancePhrase) === '') {
            return 'Speak the closing phrase ONLY when all coverage topics have been addressed '
                .'OR the follow-up budget is exhausted, AND '.$floor.'. '
                .'Do NOT close after the first answer unless these conditions already hold.';
        }

        return 'When all coverage topics have been addressed OR the follow-up budget is '
            .'exhausted, AND '.$floor.', you MUST end your turn by saying this sentence '
            .'exactly, word for word, as your final sentence: "'.$advancePhrase.'" '
            .'Say it verbatim — do not paraphrase, translate or add to it. '
            .'Do NOT say it before these conditions hold.';
    }

    /**
     * Assemble the full prompt from section strings.
     */
    private function assemblePrompt(
        string $competencyCode,
        string $openingSection,
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
            $openingSection,
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
