<?php

declare(strict_types=1);

/**
 * Conversation Engine configuration (C8 — Interview Conversation).
 *
 * Keys:
 *   prompt_version    — conversation prompt template version, stamped by SystemPromptComposer
 *                       on every composed prompt. Distinct lifecycle from scoring.prompt_version —
 *                       do NOT reuse config/scoring.php. Bump on a change to the PHP structure
 *                       of the prompt or to OpeningTextComposer; fragment text is versioned by
 *                       the stored prompt set, not by this string.
 *   prompt_source     — where the prompt TEXT comes from: `db` (default, the active stored
 *                       prompt set) or `baseline` (the code baseline, no database read).
 *                       `baseline` is the break-glass for a broken or missing active set;
 *                       any other value fails every /start with a 422 until corrected.
 *   followup_budget   — default follow-up budget per competency (max N per competency),
 *                       ON TOP OF the primary questions — never merged with their count
 *                       (framework-catalogue-authoring PR7, D7). N=4 RATIFIED 2026-08-25,
 *                       closing the C8 OQ-1 that had been open since the conversation
 *                       engine shipped. A per-project override arrives with the
 *                       `project-followup-budget` change.
 *   min_questions     — minimum questions (the opening/first primary included) before the
 *                       avatar may speak the closing phrase. CLAMPED by SystemPromptComposer
 *                       to what the primaries plus the budget permit — a minimum above
 *                       `count(primary_questions) + followup_budget` would be unsatisfiable
 *                       and would strand the competency at its session cap.
 * There is deliberately NO nudge_min_chars key here. This block used to document
 * one, describing a "platform level" default that does not exist: the array
 * below never returned it, .env.example never named it, and nothing in the repo
 * read it. The real and only source is the `projects.nudge_min_chars` column,
 * passed to the composer at InterviewController::start(). An operator who
 * believed the prose would have set an env var that did nothing, silently and
 * forever — the same two-documents-one-truth drift CLAUDE.md records for
 * AGENTS.md and for the BARS indicator count.
 *
 * REQ: config/conversation.php (C8 RV-4)
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Conversation Prompt Version
    |--------------------------------------------------------------------------
    |
    | Template version string stamped by SystemPromptComposer onto every
    | composed system prompt. Bump it when the PHP structure of the prompt or
    | OpeningTextComposer changes. A change to the fragment TEXT does not need a
    | bump: that text lives in a sealed prompt set, and every session records the
    | set it ran under (`{version}+s{id}.{sha12}`) in its durable stamp.
    |
    | Distinct from scoring.prompt_version — conversation versioning must be
    | wired independently of scoring (KD-3 mirrors C9 discipline).
    |
    */
    'prompt_version' => env('CONVERSATION_PROMPT_VERSION', 'conv-2026-09-04'),

    /*
    |--------------------------------------------------------------------------
    | Conversation Prompt Source
    |--------------------------------------------------------------------------
    |
    | `db`       the ACTIVE stored prompt set (conversation_prompt_sets). A missing,
    |            ambiguous, tampered or incomplete set is a hard failure: 422
    |            `composition_error` on /start, with no baseline fallback.
    | `baseline` the code baseline (BaselinePromptFragments), no database read.
    |            Break-glass: set CONVERSATION_PROMPT_SOURCE=baseline, no redeploy
    |            of code, to keep interviews running while the stored set is repaired.
    |
    | Validated on first use (App\Enums\PromptSource): an unknown value is never
    | mapped to either source. `beai:deploy` refuses to deploy with one.
    |
    */
    'prompt_source' => env('CONVERSATION_PROMPT_SOURCE', 'db'),

    /*
    |--------------------------------------------------------------------------
    | Follow-Up Budget
    |--------------------------------------------------------------------------
    |
    | Default maximum follow-up questions the avatar may ask per competency.
    | RATIFIED 2026-08-25 at 4 (was 2, provisional since C8). Total questions per
    | competency is this value PLUS the competency's primary-question count —
    | never a value the primaries are merged into (framework-catalogue-
    | authoring PR7, D7).
    |
    | A nullable per-project override follows as `project-followup-budget`;
    | SystemPromptComposer already reads whatever budget it is handed, so nothing
    | in the composer changes when that lands.
    |
    */
    'followup_budget' => (int) env('CONVERSATION_FOLLOWUP_BUDGET', 4),

    /*
    |--------------------------------------------------------------------------
    | Minimum Question Count
    |--------------------------------------------------------------------------
    |
    | The avatar must not speak the closing phrase before asking at least this
    | many questions in a competency, counting the opening question (which is
    | the competency's first primary — D7).
    |
    | SystemPromptComposer CLAMPS this to
    | `min(value, count(primary_questions) + followup_budget)`. Do not rely on
    | configuration discipline to keep the two in agreement: a minimum the
    | primaries plus the budget cannot satisfy is an instruction the avatar
    | can never obey, and the observed consequence is the competency running
    | to its session cap and HeyGen killing it with MAX_DURATION_REACHED.
    |
    */
    'min_questions' => (int) env('CONVERSATION_MIN_QUESTIONS', 4),

    /*
    |--------------------------------------------------------------------------
    | Single-Session Interview (tavus-single-session-interview)
    |--------------------------------------------------------------------------
    |
    | max_context_chars       ceiling on the serialised multi-competency context;
    |                         a longer remaining list is truncated to a prefix.
    | ceiling_headroom_seconds a conversation within this many seconds of its
    |                         provider ceiling is not continued (retuned after G-B).
    | boundary_grace_turns    extra substantive turns beyond 1 + follow_up_budget
    |                         before a competency boundary is due.
    |
    */
    'max_context_chars' => (int) env('CONVERSATION_MAX_CONTEXT_CHARS', 40000),
    'ceiling_headroom_seconds' => (int) env('CONVERSATION_CEILING_HEADROOM_SECONDS', 480),
    'boundary_grace_turns' => (int) env('CONVERSATION_BOUNDARY_GRACE_TURNS', 1),

];
