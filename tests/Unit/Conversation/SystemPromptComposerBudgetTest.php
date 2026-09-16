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

test('openingSpokeFirstPrimary states primary 1 was already spoken and tells the model to continue from primary 2', function (): void {
    $role = Role::factory()->create(['code' => 'SPBO_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'SPBO_'.uniqid()]);
    budgetTestMakeIndicator($role->id, $competency->id);

    $result = budgetTestComposer()->compose(
        competencyCode: $competency->code,
        roleId: $role->id,
        competencyId: $competency->id,
        projectLocale: 'en',
        followUpBudget: 4,
        nudgeMinChars: null,
        primaryQuestions: ['Primary one.', 'Primary two.'],
        openingSpokeFirstPrimary: true,
    );

    expect($result->text)->toContain('Primary question 1 has ALREADY been spoken')
        ->and($result->text)->toContain('Continue from primary question 2');
});

test('openingSpokeFirstPrimary=false (e.g. a resume) states no such thing', function (): void {
    $role = Role::factory()->create(['code' => 'SPBR_'.uniqid()]);
    $competency = Competency::factory()->create(['code' => 'SPBR_'.uniqid()]);
    budgetTestMakeIndicator($role->id, $competency->id);

    $result = budgetTestComposer()->compose(
        competencyCode: $competency->code,
        roleId: $role->id,
        competencyId: $competency->id,
        projectLocale: 'en',
        followUpBudget: 4,
        nudgeMinChars: null,
        primaryQuestions: ['Primary one.', 'Primary two.'],
        openingSpokeFirstPrimary: false,
    );

    expect($result->text)->not->toContain('ALREADY been spoken');
});
