<?php

declare(strict_types=1);

/**
 * db-driven-conversation-prompts PR6a: the resolver turns the ACTIVE stored
 * prompt set into a verified template set, or refuses with a distinct reason.
 * Sets are built through real rows sealed by `PromptSetSeal`, the way a publish
 * will write them, so each failure test breaks exactly one thing.
 */

use App\DTOs\Conversation\ResolvedPromptSet;
use App\Enums\PromptFragmentKey;
use App\Exceptions\Conversation\CompositionException;
use App\Exceptions\Conversation\PromptTemplateUnresolvableException as Unresolvable;
use App\Services\Conversation\PromptSetResolver;
use App\Support\Conversation\BaselinePromptFragments;
use App\Support\Conversation\PromptSetSeal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(fn () => PromptSetResolver::flushCache());

/**
 * Insert a sealed set. Options: `label`, `active`, `locales`, `drop` (en keys to omit),
 * `extra` (en key => body), `bodies` (en key => body, sealed), `tamper` (en key => body written
 * AFTER sealing), `overrides` (rows without prompt_set_id), `sealed_overrides` (what the seal covers, if different), `hash` (stored hash instead of the seal).
 *
 * @param  array<string, mixed>  $options
 */
function makePromptSet(array $options = []): int
{
    $fragments = [];

    foreach ($options['locales'] ?? ['en', 'it'] as $locale) {
        $bodies = array_diff_key(BaselinePromptFragments::forLocale('en'), array_flip($options['drop'] ?? []));
        $bodies = array_replace($bodies, $options['bodies'] ?? [], $options['extra'] ?? []);

        foreach ($bodies as $key => $body) {
            $fragments[] = ['key' => $key, 'locale' => $locale, 'body' => $body];
        }
    }

    $overrides = $options['overrides'] ?? [];
    $seal = PromptSetSeal::seal($fragments, $options['sealed_overrides'] ?? $overrides);

    foreach ($fragments as &$row) {
        $row['body'] = $row['locale'] === 'en' ? ($options['tamper'][$row['key']] ?? $row['body']) : $row['body'];
    }
    unset($row);

    $setId = (int) DB::table('conversation_prompt_sets')->insertGetId([
        'label' => $options['label'] ?? 'set-'.uniqid(),
        'content_sha256' => $options['hash'] ?? $seal,
        'is_active' => $options['active'] ?? true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('conversation_prompt_fragments')->insert(array_map(static fn (array $f): array => [
        'prompt_set_id' => $setId, 'fragment_key' => $f['key'], 'locale' => $f['locale'], 'body' => $f['body'], 'created_at' => now(),
    ], $fragments));

    if ($overrides !== []) {
        DB::table('conversation_prompt_overrides')->insert(array_map(static fn (array $o): array => $o + [
            'prompt_set_id' => $setId, 'created_at' => now(),
        ], $overrides));
    }

    return $setId;
}

function resolveWith(string $locale = 'en', string $competency = 'COL', ?string $role = 'FLL'): ResolvedPromptSet
{
    return app(PromptSetResolver::class)->resolveActive($locale, $competency, $role);
}

function unresolvableReason(callable $resolve): string
{
    try {
        $resolve();
    } catch (Unresolvable $e) {
        expect($e)->toBeInstanceOf(CompositionException::class);

        return $e->reason;
    }

    throw new RuntimeException('Expected PromptTemplateUnresolvableException, none thrown.');
}

function overrideRow(?string $role, string $competency, string $locale, string $body): array
{
    return ['role_code' => $role, 'competency_code' => $competency, 'locale' => $locale, 'body' => $body];
}

test('a complete valid active set renders exactly like the code baseline', function (): void {
    $setId = makePromptSet(['label' => 'v7']);

    $resolved = resolveWith('en', 'COL', null);
    $baseline = BaselinePromptFragments::templateSet('en');

    expect($resolved->setId)->toBe($setId)
        ->and($resolved->setLabel)->toBe('v7')
        ->and($resolved->override)->toBeNull()
        ->and($resolved->contentSha256)->toBe(DB::table('conversation_prompt_sets')->where('id', $setId)->value('content_sha256'))
        ->and($resolved->ref())->toBe('v7+s'.$setId.'.'.substr($resolved->contentSha256, 0, 12));

    foreach (PromptFragmentKey::cases() as $key) {
        expect($resolved->templates->template($key))->toBe($baseline->template($key), $key->value);
    }

    expect($resolved->templates->render(PromptFragmentKey::Header, ['competency_code' => 'COL']))
        ->toBe($baseline->render(PromptFragmentKey::Header, ['competency_code' => 'COL']));
});

test('no active set is refused', function (): void {
    makePromptSet(['active' => false]);

    expect(unresolvableReason(fn () => resolveWith()))->toBe(Unresolvable::NO_ACTIVE_SET);
});

test('a missing key is refused even though the stored seal is correct', function (): void {
    makePromptSet(['drop' => [PromptFragmentKey::Budget->value]]);

    expect(unresolvableReason(fn () => resolveWith()))->toBe(Unresolvable::KEYS_INCOMPLETE);
});

test('an unknown extra key is refused even though the stored seal is correct', function (): void {
    makePromptSet(['extra' => ['not.a.fragment' => 'Whatever']]);

    expect(unresolvableReason(fn () => resolveWith()))->toBe(Unresolvable::KEYS_INCOMPLETE);
});

test('a locale with no rows is refused instead of falling back to another locale', function (): void {
    makePromptSet(['locales' => ['en']]);

    expect(unresolvableReason(fn () => resolveWith('it')))->toBe(Unresolvable::LOCALE_MISSING)
        ->and(resolveWith('en')->setLabel)->not->toBe('');
});

test('a body that breaks the placeholder contract is refused', function (): void {
    makePromptSet(['bodies' => [PromptFragmentKey::Budget->value => 'A budget text with no placeholder']]);

    expect(unresolvableReason(fn () => resolveWith()))->toBe(Unresolvable::CONTRACT_VIOLATED);
});

test('a body altered after sealing is refused even though it still satisfies the contract', function (): void {
    makePromptSet(['tamper' => [PromptFragmentKey::LabelOpening->value => 'OPENING (edited):']]);

    expect(unresolvableReason(fn () => resolveWith()))->toBe(Unresolvable::SEAL_MISMATCH);
});

test('the seal covers rows of locales other than the requested one', function (): void {
    makePromptSet(['hash' => hash('sha256', 'not the seal')]);

    expect(unresolvableReason(fn () => resolveWith('en')))->toBe(Unresolvable::SEAL_MISMATCH)
        ->and(unresolvableReason(fn () => resolveWith('it')))->toBe(Unresolvable::SEAL_MISMATCH);
});

test('the seal covers the overrides', function (): void {
    makePromptSet([
        'overrides' => [overrideRow(null, 'COL', 'en', 'Probe for a concrete example.')],
        'sealed_overrides' => [overrideRow(null, 'COL', 'en', 'A different text.')],
    ]);

    expect(unresolvableReason(fn () => resolveWith()))->toBe(Unresolvable::SEAL_MISMATCH);
});

test('a role-specific override beats the role-less one and a null role gets only the role-less one', function (): void {
    makePromptSet(['overrides' => [
        overrideRow(null, 'COL', 'en', 'general text'),
        overrideRow('FLL', 'COL', 'en', 'fll text'),
        overrideRow('MLL', 'COL', 'en', 'mll text'),
        overrideRow('FLL', 'INN', 'en', 'other competency'),
        overrideRow(null, 'COL', 'it', 'testo italiano'),
    ]]);

    expect(resolveWith('en', 'COL', 'FLL')->override)->toBe('fll text')
        ->and(resolveWith('en', 'COL', 'MLL')->override)->toBe('mll text')
        ->and(resolveWith('en', 'COL', 'BUL')->override)->toBe('general text')
        ->and(resolveWith('en', 'COL', null)->override)->toBe('general text')
        ->and(resolveWith('en', 'INN', null)->override)->toBeNull()
        ->and(resolveWith('en', 'INN', 'FLL')->override)->toBe('other competency')
        ->and(resolveWith('en', 'ZZZ', 'FLL')->override)->toBeNull()
        ->and(resolveWith('it', 'COL', 'FLL')->override)->toBe('testo italiano');
});

test('an override carrying a placeholder is refused when it applies', function (): void {
    makePromptSet(['overrides' => [overrideRow(null, 'COL', 'en', 'Use {{budget}} here')]]);

    expect(unresolvableReason(fn () => resolveWith('en', 'COL', null)))->toBe(Unresolvable::OVERRIDE_INVALID)
        ->and(resolveWith('en', 'INN', null)->override)->toBeNull();
});

test('a verified set is served from memory: the fragment query is not repeated, the active-set query is', function (): void {
    makePromptSet();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $count = function (string $table) use (&$queries): int {
        return count(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"'.$table.'"')));
    };

    resolveWith();
    expect($count('conversation_prompt_sets'))->toBe(1)->and($count('conversation_prompt_fragments'))->toBe(1);

    $queries = [];
    resolveWith('en', 'INN', 'MLL');
    expect($count('conversation_prompt_sets'))->toBe(1)
        ->and($count('conversation_prompt_fragments'))->toBe(0)
        ->and($count('conversation_prompt_overrides'))->toBe(0);
});

test('activating another set is seen by the next resolution with no cache flush', function (): void {
    $first = makePromptSet(['label' => 'v1']);
    expect(resolveWith()->templates->template(PromptFragmentKey::LabelOpening))->toBe('OPENING:');

    DB::table('conversation_prompt_sets')->where('id', $first)->update(['is_active' => false]);
    $second = makePromptSet(['label' => 'v2', 'bodies' => [PromptFragmentKey::LabelOpening->value => 'OPENING (v2):']]);

    $resolved = resolveWith();

    expect($resolved->setId)->toBe($second)
        ->and($resolved->setLabel)->toBe('v2')
        ->and($resolved->templates->template(PromptFragmentKey::LabelOpening))->toBe('OPENING (v2):');

    DB::table('conversation_prompt_sets')->where('id', $second)->update(['is_active' => false]);
    DB::table('conversation_prompt_sets')->where('id', $first)->update(['is_active' => true]);

    expect(resolveWith()->setLabel)->toBe('v1');
});

test('a failed verification is never cached', function (): void {
    makePromptSet(['hash' => hash('sha256', 'wrong')]);

    expect(unresolvableReason(fn () => resolveWith()))->toBe(Unresolvable::SEAL_MISMATCH)
        ->and(unresolvableReason(fn () => resolveWith()))->toBe(Unresolvable::SEAL_MISMATCH);
});
