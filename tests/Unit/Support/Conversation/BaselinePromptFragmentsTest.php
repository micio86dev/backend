<?php

declare(strict_types=1);

/**
 * BaselinePromptFragments is the code-side baseline of the 31 conversation
 * prompt fragments (db-driven-conversation-prompts, PR4b): today's literals,
 * stored trimmed, for `en` and `it` (the composer speaks English instructions
 * for every locale, so `it` is a verbatim copy of `en`).
 *
 * Its bytes are pinned end to end by the prompt goldens; this test pins the
 * shape: a complete key set per locale and a body that satisfies the contract.
 */

use App\DTOs\Conversation\PromptTemplateSet;
use App\Enums\PromptFragmentKey;
use App\Support\Conversation\BaselinePromptFragments;
use App\Support\Conversation\PromptFragmentContract;

test('every key of every baseline locale satisfies the placeholder contract', function (string $locale): void {
    $fragments = BaselinePromptFragments::forLocale($locale);
    $contract = new PromptFragmentContract;

    foreach (PromptFragmentKey::cases() as $key) {
        expect($fragments)->toHaveKey($key->value)
            ->and($contract->violations($key, $fragments[$key->value]))->toBe([], "{$locale} {$key->value}");
    }

    expect($fragments)->toHaveCount(count(PromptFragmentKey::cases()));
})->with(['en', 'it']);

test('the it baseline is a verbatim copy of the en baseline', function (): void {
    expect(BaselinePromptFragments::forLocale('it'))->toBe(BaselinePromptFragments::forLocale('en'));
});

test('a locale without its own rows falls back to the English baseline', function (): void {
    expect(BaselinePromptFragments::forLocale('fr'))->toBe(BaselinePromptFragments::forLocale('en'));
});

test('templateSet builds a complete, renderable set for the locale', function (): void {
    $set = BaselinePromptFragments::templateSet('it');

    expect($set)->toBeInstanceOf(PromptTemplateSet::class)
        ->and($set->render(PromptFragmentKey::Budget, ['budget' => 3]))->toBe('Ask at most 3 follow-up questions per competency.')
        ->and($set->render(PromptFragmentKey::Header, ['competency_code' => 'COL']))
        ->toBe('You are an adaptive interviewer conducting a BARS-based competency assessment for the [COL] competency.');
});
