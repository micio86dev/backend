<?php

declare(strict_types=1);

/**
 * RED — PR4a.3: PromptTemplateSet, the immutable fragment map for ONE locale
 * (db-driven-conversation-prompts, design N-1/N-7 and the placeholder contract).
 */

use App\DTOs\Conversation\PromptTemplateSet;
use App\Enums\PromptFragmentKey;

/**
 * A complete, contract-shaped map: each body is "<key>" followed by its own tokens.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function completeFragments(array $overrides = []): array
{
    $fragments = [];

    foreach (PromptFragmentKey::cases() as $key) {
        $tokens = array_map(static fn (string $token): string => '{{'.$token.'}}', $key->requiredTokens());
        $fragments[$key->value] = trim($key->value.' '.implode(' ', $tokens));
    }

    return $overrides + $fragments;
}

test('a complete key set builds a set that renders its own bodies', function (): void {
    $set = new PromptTemplateSet(completeFragments(['label.star' => 'STAR PROTOCOL:']));

    expect($set->render(PromptFragmentKey::LabelStar))->toBe('STAR PROTOCOL:')
        ->and($set->render(PromptFragmentKey::LabelAdvance))->toBe('label.advance');
});

test('a set missing a key is refused, and the message names the key', function (): void {
    $fragments = completeFragments();
    unset($fragments['advance.floor_one']);

    expect(fn () => new PromptTemplateSet($fragments))
        ->toThrow(InvalidArgumentException::class, 'advance.floor_one');
});

test('a set carrying a key outside the vocabulary is refused, and the message names it', function (): void {
    expect(fn () => new PromptTemplateSet(completeFragments(['advance.nope' => 'x'])))
        ->toThrow(InvalidArgumentException::class, 'advance.nope');
});

test('a body that is not a string is refused', function (): void {
    expect(fn () => new PromptTemplateSet(completeFragments(['budget' => 4])))
        ->toThrow(InvalidArgumentException::class, 'budget');
});

test('render substitutes every required token', function (): void {
    $set = new PromptTemplateSet(completeFragments([
        'budget' => 'Ask at most {{budget}} follow-up questions per competency.',
        'opening.quoted' => 'primary question {{number}}, word for word: "{{question}}"',
    ]));

    expect($set->render(PromptFragmentKey::Budget, ['budget' => 4]))
        ->toBe('Ask at most 4 follow-up questions per competency.')
        ->and($set->render(PromptFragmentKey::OpeningQuoted, ['number' => 2, 'question' => 'Tell me.']))
        ->toBe('primary question 2, word for word: "Tell me."');
});

test('a token inside a value renders literally and is never re-expanded', function (): void {
    $set = new PromptTemplateSet(completeFragments([
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

test('a missing token value is refused, and the message names key and token', function (): void {
    $set = new PromptTemplateSet(completeFragments());

    expect(fn () => $set->render(PromptFragmentKey::AdvanceWithPhrase, ['floor' => 'x']))
        ->toThrow(InvalidArgumentException::class, 'advance.with_phrase')
        ->and(fn () => $set->render(PromptFragmentKey::AdvanceWithPhrase, ['floor' => 'x']))
        ->toThrow(InvalidArgumentException::class, 'advance_phrase');
});

test('an unknown token value is refused, and the message names key and token', function (): void {
    $set = new PromptTemplateSet(completeFragments());

    expect(fn () => $set->render(PromptFragmentKey::Budget, ['budget' => 4, 'nope' => 1]))
        ->toThrow(InvalidArgumentException::class, 'budget')
        ->and(fn () => $set->render(PromptFragmentKey::Budget, ['budget' => 4, 'nope' => 1]))
        ->toThrow(InvalidArgumentException::class, 'nope');
});

test('a value that is neither a string nor an int is refused, and the message names the token', function (mixed $value): void {
    $set = new PromptTemplateSet(completeFragments());

    expect(fn () => $set->render(PromptFragmentKey::Budget, ['budget' => $value]))
        ->toThrow(InvalidArgumentException::class, 'PromptTemplateSet: value of [budget] must be string|int.');
})->with([
    'array' => [[1]],
    'null' => [null],
    'bool' => [true],
    'float' => [1.5],
]);

test('a key without tokens refuses any value', function (): void {
    $set = new PromptTemplateSet(completeFragments());

    expect(fn () => $set->render(PromptFragmentKey::Star, ['budget' => 4]))
        ->toThrow(InvalidArgumentException::class, 'budget');
});

test('the set is immutable', function (): void {
    $reflection = new ReflectionClass(PromptTemplateSet::class);

    expect($reflection->isReadOnly())->toBeTrue()
        ->and($reflection->isFinal())->toBeTrue();
});
