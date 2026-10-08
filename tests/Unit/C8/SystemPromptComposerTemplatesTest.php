<?php

declare(strict_types=1);

/**
 * The composer reads its STATIC prose through a PromptTemplateSet
 * (db-driven-conversation-prompts, PR4b-i).
 *
 * Every fragment of a marker set renders as `⟦<key>⟧` plus its tokens, so the
 * output says which keys the composer asked the set for. The byte identity of
 * the default (null) set is the golden suites' job; this file proves the seam:
 * a provided set is used for the migrated sections, and ONLY for those.
 */

use App\DTOs\Conversation\PromptTemplateSet;
use App\DTOs\Conversation\SpokenOpening;
use App\Enums\PromptFragmentKey;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Role;
use App\Services\Conversation\BarsIndicatorLoader;
use App\Services\Conversation\SystemPromptComposer;

/** Keys 4b-i reads through the set; the other 16 stay literal in the composer until 4b-ii. */
const MIGRATED_PROMPT_KEYS = [
    'header', 'label.coverage', 'label.star', 'label.follow_up', 'label.nudge', 'label.primary', 'label.advance',
    'star', 'budget', 'nudge',
    'advance.floor_one', 'advance.floor_many', 'advance.floor_with_primaries', 'advance.with_phrase', 'advance.without_phrase',
];

function markerSet(): PromptTemplateSet
{
    $fragments = [];

    foreach (PromptFragmentKey::cases() as $key) {
        $fragments[$key->value] = '⟦'.$key->value.'⟧'.implode('', array_map(
            static fn (string $token): string => ' {{'.$token.'}}',
            $key->requiredTokens(),
        ));
    }

    return new PromptTemplateSet($fragments);
}

/**
 * @param  array<string, mixed>  $case
 */
function composeWithTemplates(array $case, ?PromptTemplateSet $templates): string
{
    config(['conversation.min_questions' => 4, 'conversation.prompt_version' => 'templates-v1']);

    $case += ['budget' => 4, 'nudge' => null, 'phrase' => null, 'min' => null, 'primaries' => [], 'opening' => null];

    $role = Role::factory()->create();
    $competency = Competency::factory()->create();

    foreach ([0, 1] as $position) {
        $indicator = new BarsIndicator;
        $indicator->forceFill([
            'role_id' => $role->id,
            'competency_id' => $competency->id,
            'text' => ['en' => "Indicator {$position}"],
            'anchor_5' => ['en' => "Five {$position}"],
            'anchor_3' => ['en' => "Three {$position}"],
            'anchor_1' => ['en' => "One {$position}"],
            'position' => $position,
        ]);
        $indicator->save();
    }

    return (new SystemPromptComposer(new BarsIndicatorLoader))->compose(
        competencyCode: $competency->code,
        roleId: $role->id,
        competencyId: $competency->id,
        projectLocale: 'en',
        followUpBudget: $case['budget'],
        nudgeMinChars: $case['nudge'],
        advancePhrase: $case['phrase'],
        minQuestions: $case['min'],
        primaryQuestions: $case['primaries'],
        spokenOpening: $case['opening'],
        templates: $templates,
    )->text;
}

/** Cases chosen so that, together, every migrated key is rendered at least once. */
function templateCases(): array
{
    return [
        'no primaries, no nudge, no phrase' => [],
        'primaries, nudge, phrase, minimum 4' => ['nudge' => 100, 'phrase' => 'Move on.', 'primaries' => ['One?', 'Two?']],
        'minimum 1, resumed' => ['min' => 1, 'phrase' => 'Done.', 'primaries' => ['One?'], 'opening' => SpokenOpening::resumed(1, 1)],
    ];
}

test('a provided set supplies the migrated sections', function (array $case): void {
    $text = composeWithTemplates($case, markerSet());

    expect($text)->toContain('⟦header⟧')
        ->and($text)->toContain("⟦label.coverage⟧\n")
        ->and($text)->toContain("⟦label.star⟧\n⟦star⟧")
        ->and($text)->toContain("⟦label.follow_up⟧\n⟦budget⟧ 4")
        ->and($text)->toContain("⟦label.advance⟧\n⟦advance.");
})->with(fn (): array => array_map(static fn (array $case): array => [$case], templateCases()));

test('every migrated key is rendered somewhere across the case matrix', function (): void {
    $combined = implode("\n", array_map(
        static fn (array $case): string => composeWithTemplates($case, markerSet()),
        templateCases(),
    ));

    foreach (MIGRATED_PROMPT_KEYS as $key) {
        expect($combined)->toContain('⟦'.$key.'⟧');
    }
});

test('the sections 4b-i does not migrate ignore the provided set', function (): void {
    $text = composeWithTemplates(templateCases()['primaries, nudge, phrase, minimum 4'], markerSet());

    foreach (array_diff(array_map(static fn (PromptFragmentKey $key): string => $key->value, PromptFragmentKey::cases()), MIGRATED_PROMPT_KEYS) as $key) {
        expect($text)->not->toContain('⟦'.$key.'⟧');
    }

    expect($text)->toContain('OPENING: You have ALREADY spoken your opening line')
        ->and($text)->toContain('The numbered list below is the COMPLETE set')
        ->and($text)->toContain("⟦label.primary⟧\nThe numbered list");
});

test('the raw budget is substituted, never inflated by the primaries', function (): void {
    $text = composeWithTemplates(['budget' => 2, 'primaries' => ['One?', 'Two?', 'Three?']], markerSet());

    expect($text)->toContain('⟦budget⟧ 2');
});
