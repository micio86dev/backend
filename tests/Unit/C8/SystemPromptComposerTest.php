<?php

declare(strict_types=1);

/**
 * RED — Tasks 3.1–3.7: SystemPromptComposer (C8 Phase 3).
 *
 * Verifies (pure function, zero HTTP, zero LLM):
 * (a) Determinism — same inputs → same text and version (3.1).
 * (b) Version equals config('conversation.prompt_version') (3.1).
 * (c) Composed text contains all indicator text values for the given role (3.2).
 * (d) Composed text contains follow-up budget instruction and end_phrase advance rule (3.3).
 * (e) nudge_min_chars injected when set; no nudge section when null/0 (3.4).
 * (f) Missing IT anchor translation → AnchorTranslationMissingException (3.5).
 * (g) project_locale = 'it' with IT factory translations → Italian text in output (3.6).
 * (h) Empty indicator collection → CompositionException (3.7).
 *
 * Spec: REQ System-Prompt Composition · REQ SA-02 · REQ SA-03 · REQ i18n hard-fail.
 * REQ: SystemPromptComposer (C8 Phase 3)
 */

use App\DTOs\Conversation\ComposedPrompt;
use App\Exceptions\Conversation\CompositionException;
use App\Exceptions\Scoring\AnchorTranslationMissingException;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Role;
use App\Services\Conversation\BarsIndicatorLoader;
use App\Services\Conversation\SystemPromptComposer;

// ─── Factory helpers ──────────────────────────────────────────────────────────

/**
 * Create a BarsIndicator with full EN translations for SystemPromptComposer tests.
 *
 * @param  array<string, mixed>  $translations  Override specific translatable fields.
 */
function composerMakeIndicator(
    int $roleId,
    int $competencyId,
    int $position,
    array $translations = []
): BarsIndicator {
    $defaults = [
        'text' => ['en' => "EN indicator text {$position}", 'it' => "IT testo indicatore {$position}"],
        'anchor_5' => ['en' => "EN anchor 5 pos {$position}",  'it' => "IT ancoraggio 5 pos {$position}"],
        'anchor_3' => ['en' => "EN anchor 3 pos {$position}",  'it' => "IT ancoraggio 3 pos {$position}"],
        'anchor_1' => ['en' => "EN anchor 1 pos {$position}",  'it' => "IT ancoraggio 1 pos {$position}"],
    ];

    $merged = array_merge($defaults, $translations);

    $indicator = new BarsIndicator;
    $indicator->forceFill([
        'role_id' => $roleId,
        'competency_id' => $competencyId,
        'text' => $merged['text'],
        'anchor_5' => $merged['anchor_5'],
        'anchor_3' => $merged['anchor_3'],
        'anchor_1' => $merged['anchor_1'],
        'position' => $position,
    ]);
    $indicator->save();

    return $indicator;
}

/**
 * Build a SystemPromptComposer with a real BarsIndicatorLoader.
 */
function makeComposer(): SystemPromptComposer
{
    return new SystemPromptComposer(new BarsIndicatorLoader);
}

// ─── Tests ───────────────────────────────────────────────────────────────────

test('(a) determinism — same inputs yield identical text and version', function (): void {
    $role = Role::factory()->create(['code' => 'DET_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'DET_'.uniqid()]);
    composerMakeIndicator($role->id, $competency->id, 0);
    composerMakeIndicator($role->id, $competency->id, 1);
    composerMakeIndicator($role->id, $competency->id, 2);

    $composer = makeComposer();

    $first = $composer->compose($competency->code, $role->id, $competency->id, 'en', 2, null);
    $second = $composer->compose($competency->code, $role->id, $competency->id, 'en', 2, null);

    expect($first)->toBeInstanceOf(ComposedPrompt::class);
    expect($first->text)->toBe($second->text);
    expect($first->version)->toBe($second->version);
});

test('(b) version equals config(\'conversation.prompt_version\')', function (): void {
    $role = Role::factory()->create(['code' => 'VER_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'VER_'.uniqid()]);
    composerMakeIndicator($role->id, $competency->id, 0);

    $composer = makeComposer();
    $result = $composer->compose($competency->code, $role->id, $competency->id, 'en', 2, null);

    expect($result->version)->toBe(config('conversation.prompt_version'));
});

test('(c) composed text contains all indicator text values (EN, 3 indicators)', function (): void {
    $role = Role::factory()->create(['code' => 'IND_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'IND_'.uniqid()]);

    composerMakeIndicator($role->id, $competency->id, 0, [
        'text' => ['en' => 'Demonstrates clear strategic thinking', 'it' => 'Dimostra pensiero strategico'],
    ]);
    composerMakeIndicator($role->id, $competency->id, 1, [
        'text' => ['en' => 'Builds cross-functional relationships', 'it' => 'Costruisce relazioni interfunzionali'],
    ]);
    composerMakeIndicator($role->id, $competency->id, 2, [
        'text' => ['en' => 'Consistently delivers on commitments', 'it' => 'Mantiene gli impegni costantemente'],
    ]);

    $composer = makeComposer();
    $result = $composer->compose($competency->code, $role->id, $competency->id, 'en', 2, null);

    expect($result->text)->toContain('Demonstrates clear strategic thinking');
    expect($result->text)->toContain('Builds cross-functional relationships');
    expect($result->text)->toContain('Consistently delivers on commitments');
});

test('(d) composed text contains budget instruction (N=2) and end_phrase advance rule', function (): void {
    $role = Role::factory()->create(['code' => 'BUD_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'BUD_'.uniqid()]);
    composerMakeIndicator($role->id, $competency->id, 0);

    $composer = makeComposer();
    $result = $composer->compose($competency->code, $role->id, $competency->id, 'en', 2, null);

    // Budget assertion — pin the instruction phrase, not a bare digit that appears incidentally
    expect($result->text)->toContain('at most 2 follow-up');

    // end_phrase advance rule must be present
    expect($result->text)->toContain('end_phrase');
});

test('(d2) budget N=3 is injected into the prompt text', function (): void {
    $role = Role::factory()->create(['code' => 'BUD3_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'BUD3_'.uniqid()]);
    composerMakeIndicator($role->id, $competency->id, 0);

    $composer = makeComposer();
    $result = $composer->compose($competency->code, $role->id, $competency->id, 'en', 3, null);

    expect($result->text)->toContain('at most 3 follow-up');
});

test('(e) nudge_min_chars=100 — prompt contains "100" and re-prompt instruction', function (): void {
    $role = Role::factory()->create(['code' => 'NUD_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'NUD_'.uniqid()]);
    composerMakeIndicator($role->id, $competency->id, 0);

    $composer = makeComposer();
    $result = $composer->compose($competency->code, $role->id, $competency->id, 'en', 2, 100);

    // Pin the actual nudge instruction, not just the incidental number
    expect($result->text)->toContain('shorter than 100 characters');
    expect($result->text)->toContain('re-prompt once');
    expect($result->text)->toContain('does NOT consume a follow-up budget slot');
});

test('(e2) nudge_min_chars=null — no nudge section present', function (): void {
    $role = Role::factory()->create(['code' => 'NODNUL_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'NODNUL_'.uniqid()]);
    composerMakeIndicator($role->id, $competency->id, 0);

    $composer = makeComposer();
    $result = $composer->compose($competency->code, $role->id, $competency->id, 'en', 2, null);

    // When nudge is disabled, no nudge-section language must leak into the prompt.
    expect($result->text)->not->toContain('re-prompt once');
    expect($result->text)->not->toContain('characters');
});

test('(f) missing IT anchor translation → AnchorTranslationMissingException', function (): void {
    $role = Role::factory()->create(['code' => 'MISS_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'MISS_'.uniqid()]);

    // EN-only indicator: no 'it' translations
    composerMakeIndicator($role->id, $competency->id, 0, [
        'text' => ['en' => 'EN text only'],      // no 'it'
        'anchor_5' => ['en' => 'EN anchor 5 only'],  // no 'it'
        'anchor_3' => ['en' => 'EN anchor 3 only'],  // no 'it'
        'anchor_1' => ['en' => 'EN anchor 1 only'],  // no 'it'
    ]);

    $composer = makeComposer();

    expect(fn () => $composer->compose($competency->code, $role->id, $competency->id, 'it', 2, null))
        ->toThrow(AnchorTranslationMissingException::class);
});

test('(g) project_locale=it with IT factory translations → Italian text in output; no EN anchor text', function (): void {
    $role = Role::factory()->create(['code' => 'IT_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'IT_'.uniqid()]);

    composerMakeIndicator($role->id, $competency->id, 0, [
        'text' => ['en' => 'EN_TEXT_MARKER',    'it' => 'IT_TESTO_MARKER'],
        'anchor_5' => ['en' => 'EN_ANCH5_MARKER',   'it' => 'IT_ANCR5_MARKER'],
        'anchor_3' => ['en' => 'EN_ANCH3_MARKER',   'it' => 'IT_ANCR3_MARKER'],
        'anchor_1' => ['en' => 'EN_ANCH1_MARKER',   'it' => 'IT_ANCR1_MARKER'],
    ]);

    $composer = makeComposer();
    $result = $composer->compose($competency->code, $role->id, $competency->id, 'it', 2, null);

    // Italian markers must appear
    expect($result->text)->toContain('IT_TESTO_MARKER');
    expect($result->text)->toContain('IT_ANCR5_MARKER');

    // English markers must NOT appear
    expect($result->text)->not->toContain('EN_TEXT_MARKER');
    expect($result->text)->not->toContain('EN_ANCH5_MARKER');
});

test('(h) unknown locale with no matching translations → hard-fails, never silently falls back', function (): void {
    $role = Role::factory()->create(['code' => 'UNK_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'UNK_'.uniqid()]);

    // EN + IT authored, but the requested locale ('de') has none — must NOT fall back to EN/IT
    composerMakeIndicator($role->id, $competency->id, 0, [
        'text' => ['en' => 'EN text', 'it' => 'IT testo'],
        'anchor_5' => ['en' => 'EN anchor 5', 'it' => 'IT ancora 5'],
        'anchor_3' => ['en' => 'EN anchor 3', 'it' => 'IT ancora 3'],
        'anchor_1' => ['en' => 'EN anchor 1', 'it' => 'IT ancora 1'],
    ]);

    $composer = makeComposer();

    expect(fn () => $composer->compose($competency->code, $role->id, $competency->id, 'de', 2, null))
        ->toThrow(AnchorTranslationMissingException::class);
});

test('(h) empty indicator collection → CompositionException', function (): void {
    $role = Role::factory()->create(['code' => 'EMPTY_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'EMPTY_'.uniqid()]);
    // No indicators for this pair

    $composer = makeComposer();

    expect(fn () => $composer->compose($competency->code, $role->id, $competency->id, 'en', 2, null))
        ->toThrow(CompositionException::class);
});

// ─── star-interviewer-protocol ───────────────────────────────────────────────

/**
 * Compose a prompt with one fully-translated indicator, for text assertions.
 */
function starPrompt(int $budget = 4, ?int $minQuestions = null, ?string $advancePhrase = null): string
{
    $role = Role::factory()->create(['code' => 'STAR_ROLE_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'STAR_'.uniqid()]);
    composerMakeIndicator($role->id, $competency->id, 0);

    return makeComposer()->compose(
        competencyCode: $competency->code,
        roleId: $role->id,
        competencyId: $competency->id,
        projectLocale: 'en',
        budget: $budget,
        nudgeMinChars: null,
        advancePhrase: $advancePhrase,
        minQuestions: $minQuestions,
    )->text;
}

/** Hard-wrap-insensitive view, for assertions about WHAT the prompt says. */
function starPromptReflowed(int $budget = 4, ?int $minQuestions = null, ?string $advancePhrase = null): string
{
    return (string) preg_replace('/\s+/', ' ', starPrompt($budget, $minQuestions, $advancePhrase));
}

// ─── Group 1: the clamp (design D-1/D-2) ─────────────────────────────────────

test('(i) task 1.1 — a minimum below the budget ceiling is used as configured', function (): void {
    expect(starPromptReflowed(budget: 4, minQuestions: 4))
        ->toContain('at least 4 questions');
});

test('(j) task 1.2 — a minimum exceeding the budget is CLAMPED, never thrown', function (): void {
    // budget 2 permits 3 questions (1 opening + 2 follow-ups). A configured
    // minimum of 6 would instruct the avatar to ask at least 6 while asking at
    // most 3 — unsatisfiable. It then never speaks the closing phrase, the
    // competency runs to its session cap, and HeyGen kills the session with
    // MAX_DURATION_REACHED. This system has shipped that defect once already.
    $prompt = starPromptReflowed(budget: 2, minQuestions: 6);

    expect($prompt)->toContain('at least 3 questions')
        ->and($prompt)->not->toContain('at least 6 questions');
});

test('(k) task 1.3 — a zero budget still yields a satisfiable minimum of 1', function (): void {
    expect(starPromptReflowed(budget: 0, minQuestions: 4))
        ->toContain('at least 1 question');
});

test('(l) task 1.4 — a zero or negative configured minimum floors at 1, never 0', function (): void {
    // A stated minimum of zero reads as permission to close before asking anything.
    expect(starPromptReflowed(budget: 4, minQuestions: 0))
        ->toContain('at least 1 question')
        ->not->toContain('at least 0 question');

    expect(starPromptReflowed(budget: 4, minQuestions: -3))
        ->toContain('at least 1 question');
});

test('(m) task 1.5 — GRID: the stated minimum never exceeds what the budget permits', function (): void {
    // The property that keeps budget exhaustion always able to satisfy the
    // minimum, and therefore keeps the "OR budget exhausted" escape hatch
    // reachable under every configuration. Asserted across the grid rather than
    // trusted by reading the arithmetic.
    foreach ([0, 1, 2, 4, 8] as $budget) {
        foreach ([1, 2, 4, 6, 10] as $minimum) {
            $prompt = starPromptReflowed(budget: $budget, minQuestions: $minimum);

            $expected = max(1, min($minimum, $budget + 1));

            expect($prompt)->toContain("at least {$expected} question");

            // And never a value above the ceiling.
            for ($over = $budget + 2; $over <= 12; $over++) {
                expect($prompt)->not->toContain("at least {$over} question");
            }
        }
    }
});

// ─── Group 2: the STAR section ───────────────────────────────────────────────

test('(n) task 2.1 — the prompt names all five STAR elements', function (): void {
    $prompt = starPromptReflowed();

    expect($prompt)->toContain('STAR');

    foreach (['Situation', 'Task', 'Context', 'Action', 'Result'] as $element) {
        expect($prompt)->toContain($element);
    }
});

test('(o) task 2.2 — the avatar is told to target the LEAST covered element', function (): void {
    expect(starPromptReflowed())
        ->toContain('least covered');
});

test('(p) task 2.3 — Action and Result carry the evaluator\'s own requirements', function (): void {
    // EVALUATION_STANDARDS in the SCORING prompt refuses a 4 or 5 without
    // concrete personal actions and a measurable outcome. The interviewer must
    // ask for what the evaluator is required to find — the two prompts are a
    // matched pair.
    $prompt = starPromptReflowed();

    expect($prompt)->toContain('measurable outcome')
        ->and($prompt)->toContain('candidate personally did')
        ->and($prompt)->toContain('not what the team did');
});

test('(q) task 2.4 — an inapplicable element counts as covered and is not re-asked', function (): void {
    // Otherwise an element the episode simply does not have becomes an
    // unreachable coverage condition, and the competency cannot advance.
    $prompt = starPromptReflowed();

    expect($prompt)->toContain('genuinely does not apply')
        ->and($prompt)->toContain('treat it as covered');
});

// ─── Group 3: the same-episode constraint ────────────────────────────────────

test('(r) task 3.1/3.2 — every follow-up deepens ONE episode; no second example', function (): void {
    $prompt = starPromptReflowed();

    expect($prompt)->toContain('the SAME episode')
        ->and($prompt)->toContain('Do NOT ask for a second or different example');
});

test('(s) task 3.3 — an episode with no assessable behaviour may be replaced', function (): void {
    // Without this a candidate who opens with a poor example is locked into it
    // for the whole competency.
    expect(starPromptReflowed())
        ->toContain('no assessable behaviour at all');
});

test('(t) task 3.4 — the same-episode rule is stated ONCE, not three times', function (): void {
    // The reference log repeats it three times. Repetition competes with the
    // other rules for the model's attention; if once proves insufficient in the
    // live smoke we add repetition WITH EVIDENCE, not on the reference's
    // authority.
    $occurrences = substr_count(
        starPromptReflowed(),
        'Do NOT ask for a second or different example',
    );

    expect($occurrences)->toBe(1);
});

// ─── Group 4: the advance rule ───────────────────────────────────────────────

test('(u) task 4.1 — with a phrase, the advance condition carries the minimum', function (): void {
    $prompt = starPromptReflowed(budget: 4, minQuestions: 4, advancePhrase: 'Grazie, passiamo oltre.');

    expect($prompt)->toContain('at least 4 questions')
        ->and($prompt)->toContain('Grazie, passiamo oltre.');
});

test('(v) task 4.2 — the no-phrase fallback branch also carries the minimum', function (): void {
    $prompt = starPromptReflowed(budget: 4, minQuestions: 4, advancePhrase: null);

    expect($prompt)->toContain('at least 4 questions')
        ->and($prompt)->toContain('Do NOT close after the first answer');
});

test('(w) task 4.3 — the verbatim-phrase instruction survives untouched', function (): void {
    // This is the fix for a real incident: the avatar was told to speak a
    // placeholder it had never been given, so matchesEndPhrase() never fired.
    $prompt = starPromptReflowed(advancePhrase: 'Grazie, passiamo oltre.');

    expect($prompt)->toContain('word for word')
        ->and($prompt)->toContain('Say it verbatim');
});

test('(x) task 4.4 — an exhausted budget still permits closing', function (): void {
    expect(starPromptReflowed())
        ->toContain('follow-up budget');
});

// ─── Group 5: purity ─────────────────────────────────────────────────────────

test('(y) task 5.7 — identical arguments compose an identical prompt', function (): void {
    $role = Role::factory()->create(['code' => 'PURE_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'PURE_'.uniqid()]);
    composerMakeIndicator($role->id, $competency->id, 0);

    $composer = makeComposer();

    $args = [
        'competencyCode' => $competency->code,
        'roleId' => $role->id,
        'competencyId' => $competency->id,
        'projectLocale' => 'en',
        'budget' => 4,
        'nudgeMinChars' => null,
        'advancePhrase' => 'Grazie.',
        'minQuestions' => 4,
    ];

    expect($composer->compose(...$args)->text)->toBe($composer->compose(...$args)->text);
});
