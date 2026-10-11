<?php

declare(strict_types=1);

/**
 * PromptFragmentKey is the vocabulary of the 32 prompt fragments
 * `SystemPromptComposer` renders (db-driven-conversation-prompts, design N-1).
 *
 * Nothing reads the enum yet; this pins the vocabulary and each key's token set.
 */

use App\Enums\PromptFragmentKey;

test('the vocabulary is exactly the 32 dotted keys of design N-1, in order', function (): void {
    $values = array_map(static fn (PromptFragmentKey $key): string => $key->value, PromptFragmentKey::cases());

    expect($values)->toBe([
        'header',
        'label.opening', 'label.coverage', 'label.override', 'label.star',
        'label.follow_up', 'label.nudge', 'label.primary', 'label.advance',
        'star', 'budget', 'nudge',
        'opening.resumed_notice', 'opening.fallback', 'opening.quoted', 'opening.spoken_reask_all',
        'opening.spoken_resumed', 'opening.spoken_fresh', 'opening.closing', 'opening.continuation',
        'primary.none', 'primary.intro', 'primary.asked_before_one', 'primary.asked_before_many',
        'primary.progress_all_asked', 'primary.progress_last', 'primary.progress_next',
        'advance.floor_one', 'advance.floor_many', 'advance.floor_with_primaries',
        'advance.with_phrase', 'advance.without_phrase',
    ])->and($values)->toHaveCount(32)
        ->and(array_unique($values))->toHaveCount(32);
});

test('every key value fits the fragment_key varchar(48) column', function (): void {
    foreach (PromptFragmentKey::cases() as $key) {
        expect(strlen($key->value))->toBeLessThanOrEqual(48);
    }
});

test('the required tokens of each key are exactly those of the spec table', function (): void {
    $expected = [
        'header' => ['competency_code'],
        'budget' => ['budget'],
        'nudge' => ['nudge_min_chars'],
        'opening.quoted' => ['number', 'question'],
        'opening.spoken_reask_all' => ['quoted'],
        'opening.spoken_resumed' => ['quoted'],
        'opening.spoken_fresh' => ['quoted'],
        'primary.asked_before_many' => ['count'],
        'primary.progress_last' => ['spoken'],
        'primary.progress_next' => ['spoken', 'next'],
        'advance.floor_many' => ['min_questions'],
        'advance.floor_with_primaries' => ['floor'],
        'advance.with_phrase' => ['floor', 'advance_phrase'],
        'advance.without_phrase' => ['floor'],
    ];

    foreach (PromptFragmentKey::cases() as $key) {
        expect($key->requiredTokens())->toBe($expected[$key->value] ?? [], $key->value);
    }
});

test('opening.continuation is a token-free fragment', function (): void {
    expect(PromptFragmentKey::from('opening.continuation'))->toBe(PromptFragmentKey::OpeningContinuation)
        ->and(PromptFragmentKey::OpeningContinuation->requiredTokens())->toBe([]);
});

test('PromptFragmentKey::from refuses a key outside the vocabulary', function (): void {
    expect(fn () => PromptFragmentKey::from('advance.nope'))->toThrow(ValueError::class);
});
