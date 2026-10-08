<?php

declare(strict_types=1);

/**
 * PromptSetSeal is the tamper seal of a prompt set (db-driven-conversation-prompts,
 * design N-5): a SHA-256 over a canonical JSON of every fragment and override row.
 * Each assertion names the property a change to the hash input must or must not have.
 */

use App\Support\Conversation\PromptSetSeal;

$fragment = static fn (string $key, string $locale, string $body): array => ['key' => $key, 'locale' => $locale, 'body' => $body];
$override = static fn (?string $role, string $competency, string $locale, string $body): array => [
    'role_code' => $role, 'competency_code' => $competency, 'locale' => $locale, 'body' => $body,
];

test('a tiny payload hashes to a known answer computed outside PHP', function () use ($fragment, $override): void {
    $fragments = [$fragment('header', 'en', 'Hi')];
    $overrides = [$override(null, 'COL', 'en', 'Probe')];

    // The canonical JSON is part of the contract, so it is pinned literally; the digests come from
    // `printf '%s' <json> | shasum -a 256`, not from the code under test.
    expect(PromptSetSeal::canonicalJson($fragments, $overrides))
        ->toBe('{"fragments":[{"key":"header","locale":"en","body":"Hi"}],"overrides":[{"role_code":null,"competency_code":"COL","locale":"en","body":"Probe"}]}')
        ->and(PromptSetSeal::seal($fragments, $overrides))
        ->toBe('fc4468eb990234e2b62df37e161348eca95df81f1668dc9d3215b50711d29991')
        ->and(PromptSetSeal::seal([]))
        ->toBe('19317bcd3baecdbcbb97b7e9d6197d0d304b1d70d310eaa87d8b94ea48867110');
});

test('the seal does not depend on the order of the rows', function () use ($fragment, $override): void {
    $fragments = [$fragment('star', 'it', 'c'), $fragment('header', 'en', 'a'), $fragment('header', 'it', 'b')];
    $overrides = [$override('FLL', 'COL', 'en', 'x'), $override(null, 'COL', 'en', 'y'), $override(null, 'ABC', 'it', 'z')];

    expect(PromptSetSeal::seal(array_reverse($fragments), array_reverse($overrides)))
        ->toBe(PromptSetSeal::seal($fragments, $overrides));
});

test('a NULL role sorts before a named role in the canonical form', function () use ($override): void {
    $json = PromptSetSeal::canonicalJson([], [$override('AAA', 'COL', 'en', 'named'), $override(null, 'COL', 'en', 'any')]);

    expect(strpos($json, '"any"'))->toBeLessThan(strpos($json, '"named"'));
});

test('extra columns on a row are not part of the seal', function () use ($fragment): void {
    $plain = $fragment('header', 'en', 'a');

    expect(PromptSetSeal::seal([$plain + ['id' => 7, 'prompt_set_id' => 3]]))->toBe(PromptSetSeal::seal([$plain]));
});

test('a change to any field of a fragment or an override changes the seal', function () use ($fragment, $override): void {
    $base = PromptSetSeal::seal([$fragment('header', 'en', 'a')], [$override('FLL', 'COL', 'en', 'x')]);

    $variants = [
        'fragment body' => [[$fragment('header', 'en', 'b')], [$override('FLL', 'COL', 'en', 'x')]],
        'fragment key' => [[$fragment('budget', 'en', 'a')], [$override('FLL', 'COL', 'en', 'x')]],
        'fragment locale' => [[$fragment('header', 'it', 'a')], [$override('FLL', 'COL', 'en', 'x')]],
        'override body' => [[$fragment('header', 'en', 'a')], [$override('FLL', 'COL', 'en', 'y')]],
        'override role' => [[$fragment('header', 'en', 'a')], [$override('MLL', 'COL', 'en', 'x')]],
        'override competency' => [[$fragment('header', 'en', 'a')], [$override('FLL', 'INN', 'en', 'x')]],
        'override locale' => [[$fragment('header', 'en', 'a')], [$override('FLL', 'COL', 'it', 'x')]],
        'extra fragment' => [[$fragment('header', 'en', 'a'), $fragment('budget', 'en', 'a')], [$override('FLL', 'COL', 'en', 'x')]],
        'extra override' => [[$fragment('header', 'en', 'a')], [$override('FLL', 'COL', 'en', 'x'), $override(null, 'COL', 'en', 'x')]],
        'dropped override' => [[$fragment('header', 'en', 'a')], []],
    ];

    foreach ($variants as $name => [$fragments, $overrides]) {
        expect(PromptSetSeal::seal($fragments, $overrides))->not->toBe($base, $name);
    }
});

test('a NULL role and an empty-string role are different overrides', function () use ($override): void {
    expect(PromptSetSeal::seal([], [$override(null, 'COL', 'en', 'x')]))
        ->not->toBe(PromptSetSeal::seal([], [$override('', 'COL', 'en', 'x')]));
});

test('multibyte text is hashed as unescaped UTF-8, stably', function () use ($fragment): void {
    $row = $fragment('header', 'it', "Dov'è — «già» 日本 / ok");

    expect(PromptSetSeal::canonicalJson([$row]))
        ->toContain("Dov'è — «già» 日本 / ok")
        ->and(PromptSetSeal::seal([$row]))->toBe(hash('sha256', PromptSetSeal::canonicalJson([$row])))
        ->and(PromptSetSeal::seal([$row]))->toBe(PromptSetSeal::seal([$row]));
});

test('a malformed row is refused rather than sealed', function () use ($fragment): void {
    expect(fn () => PromptSetSeal::seal([['key' => 'header', 'locale' => 'en']]))
        ->toThrow(InvalidArgumentException::class, 'body')
        ->and(fn () => PromptSetSeal::seal([$fragment('header', 'en', 'a'), ['key' => 'x', 'locale' => 'en', 'body' => 3]]))
        ->toThrow(InvalidArgumentException::class, 'body');
});
