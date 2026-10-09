<?php

declare(strict_types=1);

/**
 * The composer reads its prose through a PromptTemplateSet
 * (db-driven-conversation-prompts, PR4b).
 *
 * Every fragment of a marker set renders as `⟦<key>⟧` plus its tokens, so the
 * output says which keys the composer asked the set for. The byte identity of
 * the default (null) set is the golden suites' job; this file proves the seam:
 * a provided set is used for every section, and a set that breaks the
 * placeholder contract fails composition instead of reaching the model.
 */

use App\DTOs\Conversation\PromptTemplateSet;
use App\DTOs\Conversation\SpokenOpening;
use App\Enums\PromptFragmentKey;
use App\Exceptions\Conversation\CompositionException;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Role;
use App\Services\Conversation\BarsIndicatorLoader;
use App\Services\Conversation\SystemPromptComposer;

/** Every key but the one reserved for per-competency overrides, which no composer path reads yet. */
function consumablePromptKeys(): array
{
    return array_values(array_diff(
        array_map(static fn (PromptFragmentKey $key): string => $key->value, PromptFragmentKey::cases()),
        ['label.override'],
    ));
}

/**
 * @param  array<string, string>  $overrides  Bodies that replace a key's marker body.
 */
function markerSet(array $overrides = []): PromptTemplateSet
{
    $fragments = [];

    foreach (PromptFragmentKey::cases() as $key) {
        $fragments[$key->value] = '⟦'.$key->value.'⟧'.implode('', array_map(
            static fn (string $token): string => ' {{'.$token.'}}',
            $key->requiredTokens(),
        ));
    }

    return new PromptTemplateSet($overrides + $fragments);
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

/** Cases chosen so that, together, every consumable key is rendered at least once. */
function templateCases(): array
{
    return [
        'no primaries, no nudge, no phrase' => [],
        'primaries, nudge, phrase, minimum 4' => ['nudge' => 100, 'phrase' => 'Move on.', 'primaries' => ['One?', 'Two?']],
        'minimum 1, resumed' => ['min' => 1, 'phrase' => 'Done.', 'primaries' => ['One?'], 'opening' => SpokenOpening::resumed(1, 1)],
        'resumed with a pending primary' => ['primaries' => ['One?', 'Two?', 'Three?'], 'opening' => SpokenOpening::resumed(1, 3)],
        'resumed with two primaries asked' => ['primaries' => ['A?', 'B?', 'C?', 'D?'], 'opening' => SpokenOpening::resumed(2, 4)],
        'a single primary, fresh' => ['primaries' => ['Only?']],
        'no primaries, resumed' => ['opening' => SpokenOpening::fallback(true)],
        'a later competency, fresh' => ['primaries' => ['One?', 'Two?'], 'opening' => SpokenOpening::primary(1, continuation: true)],
    ];
}

test('a provided set supplies every section', function (array $case): void {
    $text = composeWithTemplates($case, markerSet());

    expect($text)->toContain('⟦header⟧')
        ->and($text)->toContain("⟦label.coverage⟧\n")
        ->and($text)->toContain("⟦label.star⟧\n⟦star⟧")
        ->and($text)->toContain("⟦label.follow_up⟧\n⟦budget⟧ 4")
        ->and($text)->toContain("⟦label.advance⟧\n⟦advance.");
})->with(fn (): array => array_map(static fn (array $case): array => [$case], templateCases()));

test('every consumable key is rendered somewhere across the case matrix', function (): void {
    $combined = implode("\n", array_map(
        static fn (array $case): string => composeWithTemplates($case, markerSet()),
        templateCases(),
    ));

    expect(consumablePromptKeys())->toHaveCount(31);

    foreach (consumablePromptKeys() as $key) {
        expect($combined)->toContain('⟦'.$key.'⟧');
    }
});

test('label.override is the only key no case reads', function (): void {
    $combined = implode("\n", array_map(
        static fn (array $case): string => composeWithTemplates($case, markerSet()),
        templateCases(),
    ));

    expect($combined)->not->toContain('⟦label.override⟧');
});

test('the primary section is the intro, the asked-before line, the progress line and the numbered list', function (): void {
    $none = composeWithTemplates([], markerSet());
    $fresh = composeWithTemplates(['primaries' => ['One?', 'Two?']], markerSet());
    $last = composeWithTemplates(['primaries' => ['Only?']], markerSet());
    $askedOne = composeWithTemplates(['primaries' => ['A?', 'B?', 'C?'], 'opening' => SpokenOpening::resumed(1, 3)], markerSet());
    $askedMany = composeWithTemplates(['primaries' => ['A?', 'B?', 'C?', 'D?'], 'opening' => SpokenOpening::resumed(2, 4)], markerSet());
    $reaskAll = composeWithTemplates(['primaries' => ['One?'], 'opening' => SpokenOpening::resumed(1, 1)], markerSet());

    expect($none)->toContain("⟦label.primary⟧\n⟦primary.none⟧\n")
        ->and($fresh)->toContain("⟦label.primary⟧\n⟦primary.intro⟧\n⟦primary.progress_next⟧ 1 2\n\n1. One?\n2. Two?\n")
        ->and($last)->toContain("⟦primary.intro⟧\n⟦primary.progress_last⟧ 1\n\n1. Only?\n")
        ->and($askedOne)->toContain("⟦primary.intro⟧\n⟦primary.asked_before_one⟧\n⟦primary.progress_next⟧ 2 3\n\n1. A?")
        ->and($askedMany)->toContain("⟦primary.intro⟧\n⟦primary.asked_before_many⟧ 2\n⟦primary.progress_next⟧ 3 4\n\n1. A?")
        ->and($reaskAll)->toContain("⟦primary.intro⟧\n⟦primary.progress_all_asked⟧\n\n1. One?");
});

test('operator text with token-like characters reaches the numbered list untouched', function (): void {
    $text = composeWithTemplates(['primaries' => ['Why {{budget}} on Re:think? {{count}}', 'Two?']], markerSet());

    expect($text)->toContain("\n1. Why {{budget}} on Re:think? {{count}}\n2. Two?\n");
});

test('the opening paragraph is the label, the notice, the spoken variant and the closing, joined by single spaces', function (): void {
    $fresh = composeWithTemplates(['primaries' => ['One?', 'Two?']], markerSet());
    $resumed = composeWithTemplates(['primaries' => ['One?', 'Two?', 'Three?'], 'opening' => SpokenOpening::resumed(1, 3)], markerSet());
    $reaskAll = composeWithTemplates(['primaries' => ['One?'], 'opening' => SpokenOpening::resumed(1, 1)], markerSet());
    $fallback = composeWithTemplates([], markerSet());
    $fallbackResumed = composeWithTemplates(['opening' => SpokenOpening::fallback(true)], markerSet());

    expect($fresh)->toContain("\n⟦label.opening⟧ ⟦opening.spoken_fresh⟧ ⟦opening.quoted⟧ 1 One? ⟦opening.closing⟧\n")
        ->and($resumed)->toContain("\n⟦label.opening⟧ ⟦opening.resumed_notice⟧ ⟦opening.spoken_resumed⟧ ⟦opening.quoted⟧ 2 Two? ⟦opening.closing⟧\n")
        ->and($reaskAll)->toContain("\n⟦label.opening⟧ ⟦opening.resumed_notice⟧ ⟦opening.spoken_reask_all⟧ ⟦opening.quoted⟧ 1 One? ⟦opening.closing⟧\n")
        ->and($fallback)->toContain("\n⟦label.opening⟧ ⟦opening.fallback⟧\n")
        ->and($fallbackResumed)->toContain("\n⟦label.opening⟧ ⟦opening.resumed_notice⟧ ⟦opening.fallback⟧\n");
});

test('opening.continuation follows the closing, joined by one space, and only when the flag is set', function (): void {
    $continued = composeWithTemplates(['primaries' => ['One?', 'Two?'], 'opening' => SpokenOpening::primary(1, continuation: true)], markerSet());
    $first = composeWithTemplates(['primaries' => ['One?', 'Two?'], 'opening' => SpokenOpening::primary(1)], markerSet());
    $default = composeWithTemplates(['primaries' => ['One?', 'Two?']], markerSet());
    $resumed = composeWithTemplates(['primaries' => ['One?', 'Two?', 'Three?'], 'opening' => SpokenOpening::resumed(1, 3)], markerSet());
    $fallback = composeWithTemplates(['opening' => SpokenOpening::fallback()], markerSet());

    expect($continued)->toContain("\n⟦label.opening⟧ ⟦opening.spoken_fresh⟧ ⟦opening.quoted⟧ 1 One? ⟦opening.closing⟧ ⟦opening.continuation⟧\n")
        ->and($first)->not->toContain('⟦opening.continuation⟧')
        ->and($default)->not->toContain('⟦opening.continuation⟧')
        ->and($resumed)->not->toContain('⟦opening.continuation⟧')
        ->and($fallback)->not->toContain('⟦opening.continuation⟧');
});

test('operator text with token-like characters reaches the opening untouched', function (): void {
    $question = 'Why {{budget}} and {{quoted}} on Re:think?';
    $text = composeWithTemplates(['primaries' => [$question]], markerSet());

    expect($text)->toContain('⟦opening.quoted⟧ 1 '.$question.' ⟦opening.closing⟧');
});

test('a set whose advance.with_phrase lacks the phrase token fails composition', function (): void {
    $set = markerSet(['advance.with_phrase' => 'Say it now when {{floor}}.']);

    expect(fn () => composeWithTemplates(['phrase' => 'Move on.'], $set))
        ->toThrow(CompositionException::class, 'advance.with_phrase');
});

test('a set with an unknown token in a body fails composition', function (): void {
    $set = markerSet(['budget' => 'Ask at most {{budget}} follow-ups, {{surprise}}.']);

    expect(fn () => composeWithTemplates([], $set))
        ->toThrow(CompositionException::class, 'surprise');
});

test('a set with a blank body fails composition', function (): void {
    $set = markerSet(['primary.intro' => "  \u{00A0} "]);

    expect(fn () => composeWithTemplates(['primaries' => ['One?']], $set))
        ->toThrow(CompositionException::class, 'primary.intro');
});

test('the raw budget is substituted, never inflated by the primaries', function (): void {
    $text = composeWithTemplates(['budget' => 2, 'primaries' => ['One?', 'Two?', 'Three?']], markerSet());

    expect($text)->toContain('⟦budget⟧ 2');
});

test('an advance phrase with nothing speakable takes the no-phrase branch instead of failing', function (string $phrase): void {
    $text = composeWithTemplates(['phrase' => $phrase], markerSet());

    expect($text)->toContain('⟦advance.without_phrase⟧')
        ->not->toContain('⟦advance.with_phrase⟧');
})->with([
    'no-break space' => ["\u{00A0}"],
    'zero-width space' => ["\u{200B}"],
    'ideographic space' => ["\u{3000}"],
    'mixed' => [" \u{00A0}\u{200B} "],
]);

test('a fragment that cannot be rendered surfaces as a CompositionException naming the key', function (): void {
    $composer = new SystemPromptComposer(new BarsIndicatorLoader);
    $render = new ReflectionMethod($composer, 'render');

    expect(fn () => $render->invoke($composer, markerSet(), PromptFragmentKey::Budget, ['budget' => []]))
        ->toThrow(CompositionException::class, 'budget');
});
