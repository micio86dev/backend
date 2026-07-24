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
