<?php

declare(strict_types=1);

/**
 * db-driven-conversation-prompts PR5 — the immutability half of the
 * prompt-set storage (design N-4). A published set is a frozen artefact: its
 * rows are what the content hash seals, so the database itself refuses to
 * change them. Only the activation bookkeeping on the set row stays writable.
 *
 * There is deliberately NO INSERT trigger: a late INSERT into a sealed set is
 * caught by the hash (PR6a), not here.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Conversation\PromptTables;

uses(RefreshDatabase::class);

beforeEach(fn () => PromptTables::empty());

/** @return array{0: int, 1: int, 2: int} [set id, fragment id, override id] */
function promptImmutabilityFixture(): array
{
    $setId = (int) DB::table('conversation_prompt_sets')->insertGetId([
        'label' => 'sealed',
        'content_sha256' => str_repeat('b', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $fragmentId = (int) DB::table('conversation_prompt_fragments')->insertGetId([
        'prompt_set_id' => $setId,
        'fragment_key' => 'frame.header',
        'locale' => 'en',
        'body' => 'Competency {{competency_code}}',
        'created_at' => now(),
    ]);

    $overrideId = (int) DB::table('conversation_prompt_overrides')->insertGetId([
        'prompt_set_id' => $setId,
        'role_code' => null,
        'competency_code' => 'COL',
        'locale' => 'en',
        'body' => 'Probe for a concrete example.',
        'created_at' => now(),
    ]);

    return [$setId, $fragmentId, $overrideId];
}

test('UPDATE of a fragment is refused by the immutability trigger and leaves the row untouched', function (): void {
    [, $fragmentId] = promptImmutabilityFixture();

    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => DB::table('conversation_prompt_fragments')->where('id', $fragmentId)->update(['body' => 'edited'])),
        '23514',
        'conversation_prompt_content_immutable: UPDATE on table conversation_prompt_fragments',
    );

    expect(DB::table('conversation_prompt_fragments')->where('id', $fragmentId)->value('body'))
        ->toBe('Competency {{competency_code}}');
});

test('DELETE of a fragment is refused by the immutability trigger and leaves the row in place', function (): void {
    [, $fragmentId] = promptImmutabilityFixture();

    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => DB::table('conversation_prompt_fragments')->where('id', $fragmentId)->delete()),
        '23514',
        'conversation_prompt_content_immutable: DELETE on table conversation_prompt_fragments',
    );

    expect(DB::table('conversation_prompt_fragments')->where('id', $fragmentId)->exists())->toBeTrue();
});

test('UPDATE of an override is refused by the immutability trigger and leaves the row untouched', function (): void {
    [, , $overrideId] = promptImmutabilityFixture();

    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => DB::table('conversation_prompt_overrides')->where('id', $overrideId)->update(['body' => 'edited'])),
        '23514',
        'conversation_prompt_content_immutable: UPDATE on table conversation_prompt_overrides',
    );

    expect(DB::table('conversation_prompt_overrides')->where('id', $overrideId)->value('body'))
        ->toBe('Probe for a concrete example.');
});

test('DELETE of an override is refused by the immutability trigger and leaves the row in place', function (): void {
    [, , $overrideId] = promptImmutabilityFixture();

    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => DB::table('conversation_prompt_overrides')->where('id', $overrideId)->delete()),
        '23514',
        'conversation_prompt_content_immutable: DELETE on table conversation_prompt_overrides',
    );

    expect(DB::table('conversation_prompt_overrides')->where('id', $overrideId)->exists())->toBeTrue();
});

test('UPDATE of a sealed set column other than the activation bookkeeping is refused', function (string $column, mixed $value): void {
    [$setId] = promptImmutabilityFixture();

    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => DB::table('conversation_prompt_sets')->where('id', $setId)->update([$column => $value])),
        '23514',
        'conversation_prompt_content_immutable: UPDATE on table conversation_prompt_sets',
    );

    expect(DB::table('conversation_prompt_sets')->where('id', $setId)->value('content_sha256'))->toBe(str_repeat('b', 64));
})->with([
    'content_sha256' => ['content_sha256', str_repeat('c', 64)],
    'label' => ['label', 'renamed'],
    'notes' => ['notes', 'rewritten history'],
]);

test('toggling is_active, activated_at and updated_at on a set stays allowed', function (): void {
    [$setId] = promptImmutabilityFixture();
    $stamp = now()->addMinute();

    DB::table('conversation_prompt_sets')->where('id', $setId)->update([
        'is_active' => true,
        'activated_at' => $stamp,
        'updated_at' => $stamp,
    ]);
    $active = DB::table('conversation_prompt_sets')->where('id', $setId)->first();

    expect($active->is_active)->toBeTrue()
        ->and($active->activated_at)->not->toBeNull();

    DB::table('conversation_prompt_sets')->where('id', $setId)->update(['is_active' => false]);

    expect(DB::table('conversation_prompt_sets')->where('id', $setId)->value('is_active'))->toBeFalse();
});

test('a set that has fragments cannot be deleted: the foreign key restricts it', function (): void {
    [$setId] = promptImmutabilityFixture();

    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => DB::table('conversation_prompt_sets')->where('id', $setId)->delete()),
        '23503',
        'conversation_prompt_fragments_prompt_set_id_foreign',
    );

    expect(DB::table('conversation_prompt_sets')->where('id', $setId)->exists())->toBeTrue();
});

test('a set that has only overrides cannot be deleted either', function (): void {
    $setId = (int) DB::table('conversation_prompt_sets')->insertGetId([
        'label' => 'overrides-only',
        'content_sha256' => str_repeat('b', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('conversation_prompt_overrides')->insert([
        'prompt_set_id' => $setId,
        'competency_code' => 'COL',
        'locale' => 'en',
        'body' => 'Probe.',
        'created_at' => now(),
    ]);

    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => DB::table('conversation_prompt_sets')->where('id', $setId)->delete()),
        '23503',
        'conversation_prompt_overrides_prompt_set_id_foreign',
    );
});

test('a set with no children can still be deleted (a failed publish rolls back cleanly)', function (): void {
    $setId = (int) DB::table('conversation_prompt_sets')->insertGetId([
        'label' => 'empty',
        'content_sha256' => str_repeat('b', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('conversation_prompt_sets')->where('id', $setId)->delete())->toBe(1);
});
