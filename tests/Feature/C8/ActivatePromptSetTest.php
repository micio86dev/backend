<?php

declare(strict_types=1);

/**
 * db-driven-conversation-prompts PR6b: activation verifies the set exactly as
 * the resolver does, then swaps the active set inside one transaction
 * (incumbent first: the one-active index is not deferrable).
 */

use App\Actions\Conversation\ActivatePromptSet;
use App\Actions\Conversation\PublishPromptSet;
use App\Exceptions\Conversation\PromptSetException;
use App\Exceptions\Conversation\PromptTemplateUnresolvableException as Unresolvable;
use App\Models\ConversationPromptSet;
use App\Services\Conversation\PromptSetResolver;
use App\Support\Conversation\PromptSetSeal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Conversation\PromptSetPayload as Payload;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PromptSetResolver::flushCache();
    ConversationPromptSet::query()->where('is_active', true)->update(['is_active' => false]);
});

function publishSet(string $label): ConversationPromptSet
{
    return app(PublishPromptSet::class)->handle($label, null, Payload::fragments());
}

/** Insert a set the way a careless script would: no publish validation. */
function insertRawSet(string $label, array $fragments, ?string $hash = null, array $overrides = []): int
{
    $setId = (int) DB::table('conversation_prompt_sets')->insertGetId([
        'label' => $label,
        'content_sha256' => $hash ?? PromptSetSeal::seal($fragments, $overrides),
        'is_active' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('conversation_prompt_fragments')->insert(array_map(static fn (array $f): array => [
        'prompt_set_id' => $setId, 'fragment_key' => $f['key'], 'locale' => $f['locale'], 'body' => $f['body'], 'created_at' => now(),
    ], $fragments));

    if ($overrides !== []) {
        DB::table('conversation_prompt_overrides')->insert(array_map(static fn (array $o): array => $o + ['prompt_set_id' => $setId, 'created_at' => now()], $overrides));
    }

    return $setId;
}

function activeLabels(): array
{
    return ConversationPromptSet::query()->where('is_active', true)->pluck('label')->all();
}

test('activating a set makes it the only active one and records activated_at', function (): void {
    publishSet('s1');
    publishSet('s2');
    app(ActivatePromptSet::class)->handle('s1');

    $activated = app(ActivatePromptSet::class)->handle('s2');

    expect(activeLabels())->toBe(['s2'])
        ->and($activated->is_active)->toBeTrue()
        ->and($activated->activated_at)->not->toBeNull()
        ->and(ConversationPromptSet::query()->where('label', 's1')->value('is_active'))->toBeFalse();
});

test('the first activation works with no incumbent, by label or by id', function (): void {
    $set = publishSet('s1');

    app(ActivatePromptSet::class)->handle($set->id);

    expect(activeLabels())->toBe(['s1']);
});

test('re-activating the active set changes nothing', function (): void {
    publishSet('s1');
    $first = app(ActivatePromptSet::class)->handle('s1');
    $this->travel(5)->minutes();

    $again = app(ActivatePromptSet::class)->handle('s1');

    expect(activeLabels())->toBe(['s1'])
        ->and($again->activated_at->equalTo($first->activated_at))->toBeTrue();
});

test('an unknown set is refused with no side effects', function (): void {
    publishSet('s1');
    app(ActivatePromptSet::class)->handle('s1');

    try {
        app(ActivatePromptSet::class)->handle('nope');
        $this->fail('Expected PromptSetException.');
    } catch (PromptSetException $e) {
        expect($e->reason)->toBe(PromptSetException::UNKNOWN_SET);
    }

    expect(activeLabels())->toBe(['s1']);
});

test('a set that fails verification can never become active and the incumbent stays', function (string $reason, Closure $make): void {
    publishSet('good');
    app(ActivatePromptSet::class)->handle('good');
    $make();

    try {
        app(ActivatePromptSet::class)->handle('bad');
        $this->fail('Expected PromptTemplateUnresolvableException.');
    } catch (Unresolvable $e) {
        expect($e->reason)->toBe($reason);
    }

    expect(activeLabels())->toBe(['good'])
        ->and(ConversationPromptSet::query()->where('label', 'bad')->value('is_active'))->toBeFalse();
})->with([
    'tampered hash' => [Unresolvable::SEAL_MISMATCH, fn () => insertRawSet('bad', Payload::fragments(), str_repeat('a', 64))],
    'missing key' => [Unresolvable::KEYS_INCOMPLETE, fn () => insertRawSet('bad', array_slice(Payload::fragments(), 1))],
    'override with a placeholder' => [Unresolvable::OVERRIDE_INVALID, fn () => insertRawSet('bad', Payload::fragments(), null, [Payload::override(null, 'COL', 'en', 'Ask about {{budget}}.')])],
    'contract violation' => [Unresolvable::CONTRACT_VIOLATED, fn () => insertRawSet('bad', Payload::fragments(['en', 'it'], ['advance.with_phrase' => 'no token']))],
]);

test('a failure after the incumbent was deactivated rolls the swap back', function (): void {
    publishSet('s1');
    publishSet('s2');
    app(ActivatePromptSet::class)->handle('s1');

    ConversationPromptSet::updating(static function (): never {
        throw new RuntimeException('boom');
    });

    try {
        expect(fn () => app(ActivatePromptSet::class)->handle('s2'))->toThrow(RuntimeException::class, 'boom');
    } finally {
        ConversationPromptSet::flushEventListeners();
    }

    expect(activeLabels())->toBe(['s1']);
});

test('activation forgets the verified sets held in memory after commit', function (): void {
    publishSet('s1');
    app(ActivatePromptSet::class)->handle('s1');
    app(PromptSetResolver::class)->resolveActive('en', 'COL', null);
    $cache = new ReflectionProperty(PromptSetResolver::class, 'verified');

    expect($cache->getValue())->not->toBeEmpty();

    publishSet('s2');
    app(ActivatePromptSet::class)->handle('s2');

    expect($cache->getValue())->toBeEmpty();
});
