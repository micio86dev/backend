<?php

declare(strict_types=1);

/**
 * RED — Task 26.1 (framework-catalogue-authoring PR7, D7): the composer
 * budget reversal.
 *
 * Proves the deleted `$effectiveBudget = $budget + count($authoredQuestions)`
 * arithmetic stays deleted — the follow-up budget is fed to the prompt raw,
 * never inflated by the primary-question count — and that
 * `effectiveMinimum()`'s clamp is
 * `max(1, min($configured, count($primaryQuestions) + $followUpBudget))`,
 * with no separate "+1 for the opening question" term (the opening question
 * IS primary 1, already inside the primary count).
 *
 * REQ: interview-conversation — "Follow-Up Budget Applies Only On Top Of
 * Authored Primaries", "Authored Primary Questions Are The Complete Primary
 * Set (No Hidden Questions)".
 */

use App\DTOs\Conversation\SpokenOpening;
use App\Exceptions\Conversation\CompositionException;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Role;
use App\Services\Conversation\BarsIndicatorLoader;
use App\Services\Conversation\SystemPromptComposer;

function budgetTestMakeIndicator(int $roleId, int $competencyId): BarsIndicator
{
    $indicator = new BarsIndicator;
    $indicator->forceFill([
        'role_id' => $roleId,
        'competency_id' => $competencyId,
        'text' => ['en' => 'EN indicator text', 'it' => 'IT testo indicatore'],
        'anchor_5' => ['en' => 'EN anchor 5', 'it' => 'IT ancoraggio 5'],
        'anchor_3' => ['en' => 'EN anchor 3', 'it' => 'IT ancoraggio 3'],
        'anchor_1' => ['en' => 'EN anchor 1', 'it' => 'IT ancoraggio 1'],
        'position' => 0,
    ]);
    $indicator->save();

    return $indicator;
}

function budgetTestComposer(): SystemPromptComposer
{
    return new SystemPromptComposer(new BarsIndicatorLoader);
}

test('1 primary + follow_up_budget=4 states a budget of 4, never 5 or 9 (the deleted additive arithmetic)', function (): void {
    $role = Role::factory()->create(['code' => 'SPB_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'SPB_'.uniqid()]);
    budgetTestMakeIndicator($role->id, $competency->id);

    $result = budgetTestComposer()->compose(
        competencyCode: $competency->code,
        roleId: $role->id,
        competencyId: $competency->id,
        projectLocale: 'en',
        followUpBudget: 4,
        nudgeMinChars: null,
        primaryQuestions: ['Tell me about a time you led a difficult project.'],
    );

    expect($result->text)->toContain('at most 4 follow-up');
    expect($result->text)->not->toContain('at most 5 follow-up');
    expect($result->text)->not->toContain('at most 9 follow-up');
});

test('1 primary + follow_up_budget=4 caps the effective minimum at 5, never 9 or the raw configured value', function (): void {
    $role = Role::factory()->create(['code' => 'SPBT_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'SPBT_'.uniqid()]);
    budgetTestMakeIndicator($role->id, $competency->id);

    $result = budgetTestComposer()->compose(
        competencyCode: $competency->code,
        roleId: $role->id,
        competencyId: $competency->id,
        projectLocale: 'en',
        followUpBudget: 4,
        nudgeMinChars: null,
        minQuestions: 20,
        primaryQuestions: ['Tell me about a time you led a difficult project.'],
    );

    $prompt = (string) preg_replace('/\s+/', ' ', $result->text);

    // 1 primary + budget 4 = ceiling 5 — the "5 total questions" the spec
    // scenario describes (1 primary + 4 follow-ups). Never 9, and never the
    // raw configured 20.
    expect($prompt)->toContain('at least 5 question')
        ->and($prompt)->not->toContain('at least 9 question')
        ->and($prompt)->not->toContain('at least 20 question');
});

test('effectiveMinimum clamps to count(primaryQuestions) + followUpBudget with no separate opening-question term', function (): void {
    $role = Role::factory()->create(['code' => 'SPBM_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'SPBM_'.uniqid()]);
    budgetTestMakeIndicator($role->id, $competency->id);

    $result = budgetTestComposer()->compose(
        competencyCode: $competency->code,
        roleId: $role->id,
        competencyId: $competency->id,
        projectLocale: 'en',
        followUpBudget: 2,
        nudgeMinChars: null,
        minQuestions: 10,
        primaryQuestions: ['Primary one.', 'Primary two.'],
    );

    $prompt = (string) preg_replace('/\s+/', ' ', $result->text);

    // 2 primaries + budget 2 = ceiling 4. Never 5 — the old "+1 for the
    // opening question" term this PR deletes, since the opening question IS
    // primary 1 and is already inside count(primaryQuestions).
    expect($prompt)->toContain('at least 4 question')
        ->and($prompt)->not->toContain('at least 5 question')
        ->and($prompt)->not->toContain('at least 10 question');
});

test('the primary-questions section grants no latitude to invent, substitute, reorder or reword a primary', function (): void {
    $role = Role::factory()->create(['code' => 'SPBN_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'SPBN_'.uniqid()]);
    budgetTestMakeIndicator($role->id, $competency->id);

    $result = budgetTestComposer()->compose(
        competencyCode: $competency->code,
        roleId: $role->id,
        competencyId: $competency->id,
        projectLocale: 'en',
        followUpBudget: 4,
        nudgeMinChars: null,
        primaryQuestions: ['Tell me about a time you led a difficult project.'],
    );

    expect($result->text)->toContain('COMPLETE set of primary questions')
        ->and($result->text)->toContain('may NOT introduce, substitute')
        ->and($result->text)->toContain('reorder')
        ->and($result->text)->toContain('reword')
        ->and($result->text)->toContain('only generative latitude is follow-up');
});

function budgetTestPrompt(array $primaries, ?SpokenOpening $opening = null): string
{
    $role = Role::factory()->create(['code' => 'SPBO_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'SPBO_'.uniqid()]);
    budgetTestMakeIndicator($role->id, $competency->id);

    $text = budgetTestComposer()->compose(
        competencyCode: $competency->code,
        roleId: $role->id,
        competencyId: $competency->id,
        projectLocale: 'en',
        followUpBudget: 4,
        nudgeMinChars: null,
        primaryQuestions: $primaries,
        spokenOpening: $opening,
    )->text;

    return (string) preg_replace('/\s+/', ' ', $text);
}

test('multi-primary: the opening quotes primary 1 and the model continues from primary 2', function (): void {
    $prompt = budgetTestPrompt(['Primary one.', 'Primary two.'], SpokenOpening::primary(1));

    expect($prompt)->toContain('which was primary question 1, word for word: "Primary one."')
        ->and($prompt)->toContain('continue with primary question 2')
        ->and($prompt)->not->toContain('primary question 3')
        ->and($prompt)->not->toContain('asked the candidate to describe a specific episode');
});

test('single primary: no "question 2" anywhere, and everything after the opening is a follow-up', function (): void {
    $prompt = budgetTestPrompt(['Ciao! Come ti chiami?'], SpokenOpening::primary(1));

    expect($prompt)->toContain('"Ciao! Come ti chiami?"')
        ->and($prompt)->not->toContain('question 2')
        ->and($prompt)->toContain('every primary question has now been asked')
        ->and($prompt)->toContain('everything you ask from here on is a follow-up');
});

test('the default opening for a non-empty primary set is primary 1', function (): void {
    expect(budgetTestPrompt(['Primary one.', 'Primary two.']))
        ->toContain('which was primary question 1, word for word: "Primary one."')
        ->toContain('continue with primary question 2');
});

test('zero primaries: the opening states the gate-off fallback episode question, and it is the only primary', function (): void {
    $prompt = budgetTestPrompt([]);

    expect($prompt)->toContain('asked the candidate to describe a specific episode')
        ->and($prompt)->toContain('has no primary questions')
        ->and($prompt)->not->toContain('word for word: "')
        ->and($prompt)->not->toContain('COMPLETE set of primary questions');
});

test('resume with a pending primary: the opening re-asked it and earlier primaries count as asked', function (): void {
    $prompt = budgetTestPrompt(['Primary one.', 'Primary two.', 'Primary three.'], SpokenOpening::resumed(2, 3));

    expect($prompt)->toContain('interrupted and has just resumed')
        ->and($prompt)->toContain('re-asked primary question 3, word for word: "Primary three."')
        ->and($prompt)->toContain('Primary questions 1-2 were asked before the interruption')
        ->and($prompt)->toContain('every primary question has now been asked')
        ->and($prompt)->not->toContain('primary question 4');
});

test('resume with every primary already asked: the last one was re-asked and the rest is follow-ups', function (): void {
    $prompt = budgetTestPrompt(['Primary one.', 'Primary two.'], SpokenOpening::resumed(2, 2));

    expect($prompt)->toContain('Every primary question was already asked before the interruption')
        ->and($prompt)->toContain('re-asked the last one, primary question 2, word for word: "Primary two."')
        ->and($prompt)->toContain('everything you ask from here on is a follow-up')
        ->and($prompt)->not->toContain('primary question 3')
        ->and($prompt)->not->toContain('continue with primary question');
});

test('follow-ups may lead from a non-behavioural primary toward a concrete episode', function (): void {
    $prompt = budgetTestPrompt(['Ciao! Come ti chiami?']);

    expect($prompt)->toContain('lead from it toward one concrete episode')
        ->and($prompt)->toContain('A primary question does not always ask for that');
});

test('the nudge is scoped to substantive questions: a short name is a complete answer', function (): void {
    $role = Role::factory()->create(['code' => 'SPBN_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'SPBN_'.uniqid()]);
    budgetTestMakeIndicator($role->id, $competency->id);

    $prompt = (string) preg_replace('/\s+/', ' ', budgetTestComposer()->compose(
        competencyCode: $competency->code,
        roleId: $role->id,
        competencyId: $competency->id,
        projectLocale: 'en',
        followUpBudget: 4,
        nudgeMinChars: 80,
        primaryQuestions: ['Ciao! Come ti chiami?'],
    )->text);

    expect($prompt)->toContain('their name')
        ->and($prompt)->toContain('never re-prompt it');
});

test('the advance rule never forbids closing once the budget is exhausted', function (): void {
    $prompt = budgetTestPrompt(['Primary one.']);

    expect($prompt)->toContain('every primary question has been asked')
        ->and($prompt)->not->toContain('never say it before the coverage topics are addressed');
});

test('an opening naming a primary the set does not have is refused, never stated', function (): void {
    expect(fn () => budgetTestPrompt(['Primary one.'], SpokenOpening::primary(2)))
        ->toThrow(CompositionException::class)
        ->and(fn () => budgetTestPrompt(['Primary one.'], SpokenOpening::fallback()))
        ->toThrow(CompositionException::class);
});
