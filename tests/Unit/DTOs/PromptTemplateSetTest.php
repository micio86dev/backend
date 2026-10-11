<?php

declare(strict_types=1);

/**
 * PromptTemplateSet is the immutable fragment map for ONE locale: it is built
 * only from the complete key set and renders a fragment in a single
 * substitution pass (db-driven-conversation-prompts, design N-1/N-7).
 */

use App\DTOs\Conversation\PromptTemplateSet;
use App\Enums\PromptFragmentKey;

/**
 * A complete, contract-shaped map: each body is "<key>" followed by its own tokens.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function promptSetFragments(array $overrides = []): array
{
    $fragments = [];

    foreach (PromptFragmentKey::cases() as $key) {
        $tokens = array_map(static fn (string $token): string => '{{'.$token.'}}', $key->requiredTokens());
        $fragments[$key->value] = trim($key->value.' '.implode(' ', $tokens));
    }

    return $overrides + $fragments;
}

test('a complete key set builds a set that renders its own bodies', function (): void {
    $set = new PromptTemplateSet(promptSetFragments(['label.star' => 'STAR PROTOCOL:']));

    expect($set->render(PromptFragmentKey::LabelStar))->toBe('STAR PROTOCOL:')
        ->and($set->render(PromptFragmentKey::LabelAdvance))->toBe('label.advance');
});

test('template returns the stored body untouched, placeholders included', function (): void {
    $set = new PromptTemplateSet(promptSetFragments());

    expect($set->template(PromptFragmentKey::Budget))->toBe('budget {{budget}}')
        ->and($set->template(PromptFragmentKey::LabelStar))->toBe('label.star');
});

test('a set missing a key is refused, and the message names the key', function (): void {
    $fragments = promptSetFragments();
    unset($fragments['advance.floor_one']);

    expect(fn () => new PromptTemplateSet($fragments))
        ->toThrow(InvalidArgumentException::class, 'Missing: [advance.floor_one]. Unknown: [].');
});

test('a set carrying a key outside the vocabulary is refused, and the message names it', function (): void {
    expect(fn () => new PromptTemplateSet(promptSetFragments(['advance.nope' => 'x'])))
        ->toThrow(InvalidArgumentException::class, 'Missing: []. Unknown: [advance.nope].');
});

test('a set both missing a key and carrying an unknown one reports both lists', function (): void {
    $fragments = promptSetFragments(['advance.nope' => 'x']);
    unset($fragments['budget']);

    expect(fn () => new PromptTemplateSet($fragments))
        ->toThrow(InvalidArgumentException::class, 'Missing: [budget]. Unknown: [advance.nope].');
});

test('an integer-keyed list is refused, whatever it holds', function (): void {
    expect(fn () => new PromptTemplateSet(array_values(promptSetFragments())))
        ->toThrow(InvalidArgumentException::class, 'Unknown: [0, 1, 2');
});

test('a body that is not a string is refused', function (): void {
    expect(fn () => new PromptTemplateSet(promptSetFragments(['budget' => 4])))
        ->toThrow(InvalidArgumentException::class, 'budget');
});

test('render substitutes every required token', function (): void {
    $set = new PromptTemplateSet(promptSetFragments([
        'budget' => 'Ask at most {{budget}} follow-up questions per competency.',
        'opening.quoted' => 'primary question {{number}}, word for word: "{{question}}"',
    ]));

    expect($set->render(PromptFragmentKey::Budget, ['budget' => 4]))
        ->toBe('Ask at most 4 follow-up questions per competency.')
        ->and($set->render(PromptFragmentKey::OpeningQuoted, ['number' => 2, 'question' => 'Tell me.']))
        ->toBe('primary question 2, word for word: "Tell me."');
});

test('a token inside a value renders literally and is never re-expanded', function (): void {
    $set = new PromptTemplateSet(promptSetFragments([
        'advance.with_phrase' => 'AND {{floor}}, say: "{{advance_phrase}}"',
    ]));

    $rendered = $set->render(PromptFragmentKey::AdvanceWithPhrase, [
        'floor' => 'ask {{advance_phrase}} twice',
        'advance_phrase' => 'Grazie. {{floor}} {{budget}} Re:think :budget',
    ]);

    expect($rendered)->toBe(
        'AND ask {{advance_phrase}} twice, say: "Grazie. {{floor}} {{budget}} Re:think :budget"',
    );
});

test('a missing token value is refused, and the Missing list names it', function (): void {
    $set = new PromptTemplateSet(promptSetFragments());

    expect(fn () => $set->render(PromptFragmentKey::AdvanceWithPhrase, ['floor' => 'x']))
        ->toThrow(InvalidArgumentException::class, 'fragment [advance.with_phrase] takes exactly the tokens [floor, advance_phrase]. Missing: [advance_phrase]. Unknown: [].');
});

test('an unknown token value is refused, and the Unknown list names it', function (): void {
    $set = new PromptTemplateSet(promptSetFragments());

    expect(fn () => $set->render(PromptFragmentKey::Budget, ['budget' => 4, 'nope' => 1]))
        ->toThrow(InvalidArgumentException::class, 'fragment [budget] takes exactly the tokens [budget]. Missing: []. Unknown: [nope].');
});

test('a render both missing a value and carrying an unknown one reports both lists', function (): void {
    $set = new PromptTemplateSet(promptSetFragments());

    expect(fn () => $set->render(PromptFragmentKey::Budget, ['nope' => 1]))
        ->toThrow(InvalidArgumentException::class, 'Missing: [budget]. Unknown: [nope].');
});

test('a token that appears twice in a template is replaced everywhere', function (): void {
    $set = new PromptTemplateSet(promptSetFragments(['budget' => '{{budget}} then {{budget}} again']));

    expect($set->render(PromptFragmentKey::Budget, ['budget' => 4]))->toBe('4 then 4 again');
});

test('a multibyte value is substituted intact', function (): void {
    $set = new PromptTemplateSet(promptSetFragments(['opening.quoted' => '{{number}}. "{{question}}"']));

    expect($set->render(PromptFragmentKey::OpeningQuoted, ['number' => 1, 'question' => 'Così è già “ok” 日本語 🙂?']))
        ->toBe('1. "Così è già “ok” 日本語 🙂?"');
});

test('an empty value is accepted for a token that is not the advance phrase', function (): void {
    $set = new PromptTemplateSet(promptSetFragments(['budget' => 'at most [{{budget}}]']));

    expect($set->render(PromptFragmentKey::Budget, ['budget' => '']))->toBe('at most []');
});

test('a value that is neither a string nor an int is refused, and the message names the token', function (mixed $value): void {
    $set = new PromptTemplateSet(promptSetFragments());

    expect(fn () => $set->render(PromptFragmentKey::Budget, ['budget' => $value]))
        ->toThrow(InvalidArgumentException::class, 'PromptTemplateSet: value of [budget] must be string|int.');
})->with([
    'array' => [[1]],
    'null' => [null],
    'bool' => [true],
    'float' => [1.5],
]);

test('an empty advance phrase is refused: it would make the avatar say nothing', function (string $phrase): void {
    $set = new PromptTemplateSet(promptSetFragments());

    expect(fn () => $set->render(PromptFragmentKey::AdvanceWithPhrase, ['floor' => 'x', 'advance_phrase' => $phrase]))
        ->toThrow(InvalidArgumentException::class, 'PromptTemplateSet: the value of [advance_phrase] must not be empty.');
})->with([
    'empty' => [''],
    'spaces' => ['   '],
    'newline' => ["\n"],
    'nbsp and zero-width space' => ["\u{00A0}\u{200B}"],
]);

test('a phrase made of the digit zero is not empty', function (): void {
    $set = new PromptTemplateSet(promptSetFragments(['advance.with_phrase' => '{{floor}} say "{{advance_phrase}}"']));

    expect($set->render(PromptFragmentKey::AdvanceWithPhrase, ['floor' => 'x', 'advance_phrase' => 0]))->toBe('x say "0"');
});

test('a key without tokens refuses any value', function (): void {
    $set = new PromptTemplateSet(promptSetFragments());

    expect(fn () => $set->render(PromptFragmentKey::Star, ['budget' => 4]))
        ->toThrow(InvalidArgumentException::class, 'budget');
});

test('the set is immutable', function (): void {
    $reflection = new ReflectionClass(PromptTemplateSet::class);

    expect($reflection->isReadOnly())->toBeTrue()
        ->and($reflection->isFinal())->toBeTrue();
});
