<?php

declare(strict_types=1);

/**
 * db-driven-conversation-prompts PR5 — the uniqueness and integrity half of
 * the prompt-set storage. Every case provokes the REAL database constraint
 * and names it (`assertPostgresConstraintViolation`), so a missing table, a
 * typo'd column or a different constraint cannot satisfy it by accident.
 *
 * The violating statement runs inside `DB::transaction()` so it rolls back to
 * a savepoint: Postgres aborts the whole transaction on an error, and
 * `RefreshDatabase` keeps one open around each test.
 */

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Helpers\Conversation\PromptTables;

uses(RefreshDatabase::class);

beforeEach(fn () => PromptTables::empty());

const PROMPT_SCHEMA_HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

/** @param array<string, mixed> $overrides */
function promptSchemaInsertSet(string $label, array $overrides = []): int
{
    return (int) DB::table('conversation_prompt_sets')->insertGetId(array_merge([
        'label' => $label,
        'content_sha256' => PROMPT_SCHEMA_HASH,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/** @param array<string, mixed> $overrides */
function promptSchemaFragment(int $setId, array $overrides = []): array
{
    return array_merge([
        'prompt_set_id' => $setId,
        'fragment_key' => 'frame.header',
        'locale' => 'en',
        'body' => 'Competency {{competency_code}}',
        'created_at' => now(),
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function promptSchemaOverride(int $setId, array $overrides = []): array
{
    return array_merge([
        'prompt_set_id' => $setId,
        'role_code' => null,
        'competency_code' => 'COL',
        'locale' => 'en',
        'body' => 'Probe for a concrete example.',
        'created_at' => now(),
    ], $overrides);
}

test('the three prompt tables are global: none carries an organization_id column', function (): void {
    foreach (['conversation_prompt_sets', 'conversation_prompt_fragments', 'conversation_prompt_overrides'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("{$table} is missing")
            ->and(Schema::hasColumn($table, 'organization_id'))->toBeFalse("{$table} must stay global");
    }
});

test('a second active prompt set violates conversation_prompt_sets_one_active', function (): void {
    promptSchemaInsertSet('baseline', ['is_active' => true, 'activated_at' => now()]);

    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => promptSchemaInsertSet('next', ['is_active' => true])),
        '23505',
        'conversation_prompt_sets_one_active',
    );
});

test('any number of inactive prompt sets coexist', function (): void {
    promptSchemaInsertSet('one');
    promptSchemaInsertSet('two');
    promptSchemaInsertSet('three');

    expect(DB::table('conversation_prompt_sets')->where('is_active', false)->count())->toBe(3);
});

test('a duplicate prompt set label violates conversation_prompt_sets_label_unique', function (): void {
    promptSchemaInsertSet('baseline');

    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => promptSchemaInsertSet('baseline')),
        '23505',
        'conversation_prompt_sets_label_unique',
    );
});

test('a malformed content_sha256 violates conversation_prompt_sets_content_sha256_hex', function (string $hash): void {
    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => promptSchemaInsertSet('bad-hash', ['content_sha256' => $hash])),
        '23514',
        'conversation_prompt_sets_content_sha256_hex',
    );
})->with([
    'uppercase hex' => [str_repeat('A', 64)],
    'non hex' => [str_repeat('g', 64)],
    'too short' => [str_repeat('a', 63)],
]);

test('a duplicate (set, key, locale) fragment violates its unique index, while another locale is fine', function (): void {
    $setId = promptSchemaInsertSet('baseline');
    DB::table('conversation_prompt_fragments')->insert(promptSchemaFragment($setId));

    DB::table('conversation_prompt_fragments')->insert(promptSchemaFragment($setId, ['locale' => 'it']));

    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => DB::table('conversation_prompt_fragments')->insert(promptSchemaFragment($setId))),
        '23505',
        'conversation_prompt_fragments_set_key_locale_unique',
    );
});

test('a duplicate role-specific override violates conversation_prompt_overrides_role_specific_unique', function (): void {
    $setId = promptSchemaInsertSet('baseline');
    DB::table('conversation_prompt_overrides')->insert(promptSchemaOverride($setId, ['role_code' => 'ICO']));

    DB::table('conversation_prompt_overrides')->insert(promptSchemaOverride($setId, ['role_code' => 'FLL']));

    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => DB::table('conversation_prompt_overrides')->insert(promptSchemaOverride($setId, ['role_code' => 'ICO']))),
        '23505',
        'conversation_prompt_overrides_role_specific_unique',
    );
});

test('a duplicate role-less override violates conversation_prompt_overrides_role_less_unique', function (): void {
    $setId = promptSchemaInsertSet('baseline');
    DB::table('conversation_prompt_overrides')->insert(promptSchemaOverride($setId));

    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => DB::table('conversation_prompt_overrides')->insert(promptSchemaOverride($setId))),
        '23505',
        'conversation_prompt_overrides_role_less_unique',
    );
});

test('a role-less and a role-specific override for the same competency and locale coexist', function (): void {
    $setId = promptSchemaInsertSet('baseline');

    DB::table('conversation_prompt_overrides')->insert(promptSchemaOverride($setId));
    DB::table('conversation_prompt_overrides')->insert(promptSchemaOverride($setId, ['role_code' => 'ICO']));

    expect(DB::table('conversation_prompt_overrides')->where('prompt_set_id', $setId)->count())->toBe(2);
});

test('the same fragment key and override scope may exist in two different prompt sets', function (): void {
    $first = promptSchemaInsertSet('first');
    $second = promptSchemaInsertSet('second');

    foreach ([$first, $second] as $setId) {
        DB::table('conversation_prompt_fragments')->insert(promptSchemaFragment($setId));
        DB::table('conversation_prompt_overrides')->insert(promptSchemaOverride($setId));
    }

    expect(DB::table('conversation_prompt_fragments')->count())->toBe(2)
        ->and(DB::table('conversation_prompt_overrides')->count())->toBe(2);
});

test('a fragment or override pointing at a missing set violates its foreign key', function (): void {
    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => DB::table('conversation_prompt_fragments')->insert(promptSchemaFragment(999_999))),
        '23503',
        'conversation_prompt_fragments_prompt_set_id_foreign',
    );

    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => DB::table('conversation_prompt_overrides')->insert(promptSchemaOverride(999_999))),
        '23503',
        'conversation_prompt_overrides_prompt_set_id_foreign',
    );
});

test('activating a second set by UPDATE violates conversation_prompt_sets_one_active as an insert does', function (): void {
    promptSchemaInsertSet('baseline', ['is_active' => true, 'activated_at' => now()]);
    $nextId = promptSchemaInsertSet('next');

    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => DB::table('conversation_prompt_sets')->where('id', $nextId)->update(['is_active' => true])),
        '23505',
        'conversation_prompt_sets_one_active',
    );
});

/** An over-length value is refused by the column type (SQLSTATE 22001) before any CHECK can run. */
function promptSchemaAssertTooLong(Closure $statement): void
{
    try {
        DB::transaction($statement);
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('22001');

        return;
    }

    test()->fail('The over-length value was accepted.');
}

test('an over-length content hash is refused by the char(64) type, not by the hex CHECK', function (): void {
    promptSchemaAssertTooLong(fn () => promptSchemaInsertSet('long-hash', ['content_sha256' => str_repeat('a', 65)]));
});

test('over-length fragment and override columns are refused by their varchar limits', function (): void {
    $setId = promptSchemaInsertSet('limits');

    promptSchemaAssertTooLong(fn () => DB::table('conversation_prompt_fragments')->insert(promptSchemaFragment($setId, ['fragment_key' => str_repeat('k', 49)])));
    promptSchemaAssertTooLong(fn () => DB::table('conversation_prompt_fragments')->insert(promptSchemaFragment($setId, ['locale' => str_repeat('l', 9)])));
    promptSchemaAssertTooLong(fn () => DB::table('conversation_prompt_overrides')->insert(promptSchemaOverride($setId, ['role_code' => str_repeat('r', 256)])));
    promptSchemaAssertTooLong(fn () => DB::table('conversation_prompt_overrides')->insert(promptSchemaOverride($setId, ['competency_code' => str_repeat('c', 256)])));
});
