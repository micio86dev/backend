<?php

declare(strict_types=1);

/**
 * The per-competency override section of the composed prompt
 * (db-driven-conversation-prompts PR9, design N-12).
 *
 * An override is ONE appended section, headed by the `label.override` fragment,
 * between COVERAGE TOPICS and the STAR protocol. With none, the output is the
 * one it always was; with one, removing exactly that section gives back the
 * output without it, so nothing else moved, the ADVANCE RULE included.
 * Choosing WHICH override applies (role precedence, competency scope) is the
 * resolver's job and is proved in `PromptSetResolverTest` and
 * `PromptOverrideStartTest`.
 */

use App\DTOs\Conversation\PromptTemplateSet;
use App\DTOs\Conversation\SpokenOpening;
use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Role;
use App\Services\Conversation\BarsIndicatorLoader;
use App\Services\Conversation\SystemPromptComposer;
use App\Support\Conversation\BaselinePromptFragments;

const OVR_LABEL = 'COMPETENCY-SPECIFIC GUIDANCE:';

/**
 * @param  array<string, mixed>  $case
 */
function ovrCompose(array $case, ?string $override, ?PromptTemplateSet $templates = null, bool $omitOverride = false): string
{
    config(['conversation.min_questions' => 4, 'conversation.prompt_version' => 'override-v1']);

    $case += ['roleless' => false, 'nudge' => null, 'phrase' => null, 'primaries' => [], 'opening' => null];

    // Idempotent: one test composes the same competency with and without an override.
    $role = $case['roleless'] ? null : (Role::query()->where('code', 'OVR_ROLE')->first() ?? Role::factory()->create(['code' => 'OVR_ROLE']));
    $competency = Competency::query()->where('code', 'OVR_COMP')->first() ?? Competency::factory()->create(['code' => 'OVR_COMP']);

    if (! BarsIndicator::query()->where('competency_id', $competency->id)->where('role_id', $role?->id)->exists()) {
        foreach ([0, 1] as $position) {
            $indicator = new BarsIndicator;
            $indicator->forceFill([
                'role_id' => $role?->id,
                'competency_id' => $competency->id,
                'text' => ['en' => "Indicator {$position}"],
                'anchor_5' => ['en' => "Five {$position}"],
                'anchor_3' => ['en' => "Three {$position}"],
                'anchor_1' => ['en' => "One {$position}"],
                'position' => $position,
            ]);
            $indicator->save();
        }
    }

    $arguments = [
        'competencyCode' => $competency->code,
        'roleId' => $role?->id,
        'competencyId' => $competency->id,
        'projectLocale' => 'en',
        'followUpBudget' => 4,
        'nudgeMinChars' => $case['nudge'],
        'advancePhrase' => $case['phrase'],
        'primaryQuestions' => $case['primaries'],
        'spokenOpening' => $case['opening'],
        'templates' => $templates,
    ];

    if (! $omitOverride) {
        $arguments['override'] = $override;
    }

    return (new SystemPromptComposer(new BarsIndicatorLoader))->compose(...$arguments)->text;
}

/** The text from the ADVANCE RULE heading to the end (the last heading, so an operator body cannot shift it). */
function ovrAdvanceTail(string $prompt): string
{
    $at = strrpos($prompt, "\nADVANCE RULE:\n");
    expect($at)->not->toBeFalse();

    return substr($prompt, (int) $at);
}

/** @return array<string, array{0: array<string, mixed>}> */
function ovrCases(): array
{
    return [
        'bare' => [[]],
        'nudge, phrase and primaries' => [['nudge' => 100, 'phrase' => 'Move on.', 'primaries' => ['One?', 'Two?']]],
        'resumed' => [['phrase' => 'Done.', 'primaries' => ['One?', 'Two?', 'Three?'], 'opening' => SpokenOpening::resumed(1, 3)]],
        'a later competency' => [['phrase' => 'Done.', 'primaries' => ['One?'], 'opening' => SpokenOpening::primary(1, continuation: true)]],
        'potential (no role)' => [['roleless' => true, 'phrase' => 'Done.', 'primaries' => ['One?']]],
    ];
}

test('no override composes exactly what it composed before the parameter existed', function (array $case): void {
    $default = ovrCompose($case, null, omitOverride: true);

    expect(ovrCompose($case, null))->toBe($default)
        ->and($default)->not->toContain(OVR_LABEL);
})->with(fn (): array => ovrCases());

test('an override is one section, after COVERAGE TOPICS and before the STAR protocol, and nothing else moves', function (array $case): void {
    $body = "Probe for a measurable outcome.\n\nAsk what the candidate personally did.";
    $without = ovrCompose($case, null);
    $with = ovrCompose($case, $body);
    $section = OVR_LABEL."\n".$body."\n\n";

    $coverage = strpos($with, 'COVERAGE TOPICS');
    $heading = strpos($with, OVR_LABEL);
    $star = strpos($with, 'STAR COVERAGE PROTOCOL');

    expect($coverage)->toBeInt()->and($heading)->toBeGreaterThan($coverage)->and($star)->toBeGreaterThan($heading)
        ->and(substr_count($with, OVR_LABEL))->toBe(1)
        ->and(substr_count($with, $body))->toBe(1)
        // Removing the one section gives back, byte for byte, the output without it.
        ->and(str_replace($section, '', $with, $removed))->toBe($without)
        ->and($removed)->toBe(1);
})->with(fn (): array => ovrCases());

test('the ADVANCE RULE section is byte-identical with and without an override', function (array $case): void {
    $case += ['phrase' => 'Let us move on.', 'primaries' => ['One?']];

    expect(ovrAdvanceTail(ovrCompose($case, 'Always ask for a number.')))->toBe(ovrAdvanceTail(ovrCompose($case, null)));
})->with(fn (): array => ovrCases());

test('the heading is the label.override fragment of the set that is composed', function (): void {
    $templates = new PromptTemplateSet(array_replace(BaselinePromptFragments::forLocale('en'), ['label.override' => 'CUSTOM GUIDE:']));

    $with = ovrCompose([], 'Be brief.', $templates);

    expect($with)->toContain("CUSTOM GUIDE:\nBe brief.\n\nSTAR COVERAGE PROTOCOL")
        ->and($with)->not->toContain(OVR_LABEL)
        // A set composed without an override never prints the heading.
        ->and(ovrCompose([], null, $templates))->not->toContain('CUSTOM GUIDE:');
});

test('an override body is appended literally: braces, tokens, quotes and multibyte text neither render nor alter another section', function (): void {
    $body = '  Why {{budget}} and :budget and {{advance_phrase}}? Re:think "quotes" — dash, caffè, 日本語, 🙂 $1 \\0 ADVANCE RULE:  ';
    $case = ['nudge' => 100, 'phrase' => 'Move on.', 'primaries' => ['One?']];
    $without = ovrCompose($case, null);
    $with = ovrCompose($case, $body);

    expect($with)->toContain(OVR_LABEL."\n".$body."\n\n")
        ->and(str_replace(OVR_LABEL."\n".$body."\n\n", '', $with, $removed))->toBe($without)
        ->and($removed)->toBe(1)
        ->and(ovrAdvanceTail($with))->toBe(ovrAdvanceTail($without));
});

test('a blank override is no override: no heading is rendered for an empty body', function (string $blank): void {
    $case = ['phrase' => 'Done.', 'primaries' => ['One?']];

    $without = ovrCompose($case, null);
    $with = ovrCompose($case, $blank);

    expect($with)->toBe($without)
        ->and($with)->not->toContain(OVR_LABEL);
})->with([
    'empty string' => [''],
    'ascii whitespace' => ["  \n\t "],
    'no-break and zero-width spaces' => ["\u{00A0}\u{200B} \u{3000}"],
]);
