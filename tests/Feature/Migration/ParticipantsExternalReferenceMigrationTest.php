<?php

declare(strict_types=1);

/**
 * RED — candidate-external-reference A1.5: behaviour of
 * `2026_09_30_130000_add_external_reference_to_participants_table` beyond the
 * schema it leaves behind (asserted in
 * `Feature/C6/Schema/ParticipantsExternalReferenceSchemaTest`).
 *
 * Like the public-api participants migration it follows, this one disables the
 * wrapping transaction (`$withinTransaction = false`) so the indexes can be
 * built `CONCURRENTLY` on a hot table. That is what makes a partial-failure
 * rerun possible, and it only helps if every statement is ALSO individually
 * guarded. Proven by calling the same anonymous migration class directly, from
 * inside `RefreshDatabase`'s own wrapping transaction (`Feature/Migration` is
 * configured that way in `tests/Pest.php`) — the condition under which
 * `CONCURRENTLY` must NOT be attempted, because Postgres refuses it there.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const EXTERNAL_REFERENCE_INDEXES = [
    'participants_org_source_external_id_index' => '(organization_id, source, external_id) WHERE (source IS NOT NULL)',
    'participants_org_external_id_index' => '(organization_id, external_id) WHERE (external_id IS NOT NULL)',
];

function externalReferenceMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_30_130000_add_external_reference_to_participants_table.php');

    return $migration;
}

/**
 * @return list<string> the statements run while the callback executes
 */
function externalReferenceStatementsDuring(callable $callback): array
{
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $callback();

    return $statements;
}

function externalReferenceIndexDefinition(string $indexName): ?string
{
    $row = DB::selectOne(
        'SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexname = ?',
        ['participants', $indexName]
    );

    return is_object($row) && is_string($row->indexdef ?? null) ? $row->indexdef : null;
}

function externalReferenceIndexIsValid(string $indexName): bool
{
    $row = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$indexName]);

    return is_object($row) && (bool) $row->indisvalid;
}

test('the migration disables the wrapping transaction so the indexes can be built concurrently', function (): void {
    expect(externalReferenceMigration()->withinTransaction)->toBeFalse();
});

test('up() is idempotent on rerun', function (): void {
    externalReferenceMigration()->up();

    expect(Schema::hasColumn('participants', 'external_id'))->toBeTrue();
    expect(Schema::hasColumn('participants', 'source'))->toBeTrue();

    foreach (array_keys(EXTERNAL_REFERENCE_INDEXES) as $index) {
        expect(Schema::hasIndex('participants', $index))->toBeTrue();
        expect(externalReferenceIndexIsValid($index))->toBeTrue();
    }
});

test('up() re-adds a missing column and its dependent index without touching the other', function (): void {
    // Dropping the column takes the composite index that uses it with it —
    // exactly the half-applied shape a rerun after a partial failure meets.
    DB::statement('ALTER TABLE participants DROP COLUMN source');

    expect(Schema::hasColumn('participants', 'source'))->toBeFalse();
    expect(Schema::hasIndex('participants', 'participants_org_source_external_id_index'))->toBeFalse();
    expect(Schema::hasColumn('participants', 'external_id'))->toBeTrue();

    externalReferenceMigration()->up();

    expect(Schema::hasColumn('participants', 'source'))->toBeTrue();
    expect(externalReferenceIndexDefinition('participants_org_source_external_id_index'))
        ->toContain(EXTERNAL_REFERENCE_INDEXES['participants_org_source_external_id_index']);
    expect(Schema::hasIndex('participants', 'participants_org_external_id_index'))->toBeTrue();
});

test('a dropped index is rebuilt with plain CREATE INDEX (no CONCURRENTLY) inside a transaction, keeping its predicate', function (): void {
    foreach (array_keys(EXTERNAL_REFERENCE_INDEXES) as $index) {
        DB::statement("DROP INDEX {$index}");
    }

    expect(DB::transactionLevel())->toBeGreaterThan(0);

    $statements = externalReferenceStatementsDuring(fn () => externalReferenceMigration()->up());

    foreach (EXTERNAL_REFERENCE_INDEXES as $index => $definition) {
        expect(externalReferenceIndexDefinition($index))->toContain($definition);
    }

    // Postgres refuses CONCURRENTLY inside a transaction block, so a test
    // that got this wrong would have errored rather than failed an assertion.
    expect(collect($statements)->contains(fn (string $sql): bool => str_contains(strtoupper($sql), 'CONCURRENTLY')))->toBeFalse();
    expect(collect($statements)->filter(fn (string $sql): bool => str_starts_with(strtoupper($sql), 'CREATE INDEX')))->toHaveCount(2);
});

test('an INVALID index (a failed CONCURRENTLY build) is dropped and rebuilt, not left in place', function (string $index): void {
    // Schema::hasIndex() reports an index as present purely by NAME. A
    // CREATE INDEX CONCURRENTLY that failed partway leaves exactly this shape:
    // in the catalogue, but pg_index.indisvalid = false, so Postgres never
    // uses it and a rerun that only checked presence would keep saying "done".
    DB::statement("UPDATE pg_index SET indisvalid = false WHERE indexrelid = '{$index}'::regclass");

    expect(externalReferenceIndexIsValid($index))->toBeFalse();
    expect(Schema::hasIndex('participants', $index))->toBeTrue();

    externalReferenceMigration()->up();

    expect(externalReferenceIndexIsValid($index))->toBeTrue();
    expect(externalReferenceIndexDefinition($index))->toContain(EXTERNAL_REFERENCE_INDEXES[$index]);
})->with(array_keys(EXTERNAL_REFERENCE_INDEXES));

test('a valid index is left untouched by a rerun (no DROP, no CREATE)', function (): void {
    $statements = externalReferenceStatementsDuring(fn () => externalReferenceMigration()->up());

    expect(collect($statements)->contains(function (string $sql): bool {
        $sql = strtoupper($sql);

        return str_contains($sql, 'DROP INDEX') || str_starts_with($sql, 'CREATE INDEX') || str_starts_with($sql, 'ALTER TABLE');
    }))->toBeFalse();
});

test('down() removes the indexes before the columns', function (): void {
    $statements = externalReferenceStatementsDuring(fn () => externalReferenceMigration()->down());

    foreach (array_keys(EXTERNAL_REFERENCE_INDEXES) as $index) {
        expect(Schema::hasIndex('participants', $index))->toBeFalse();
    }
    expect(Schema::hasColumn('participants', 'external_id'))->toBeFalse();
    expect(Schema::hasColumn('participants', 'source'))->toBeFalse();

    $firstDropColumn = collect($statements)->search(fn (string $sql): bool => str_contains(strtoupper($sql), 'DROP COLUMN'));
    $lastDropIndex = collect($statements)->keys()->filter(
        fn (int $i): bool => str_contains(strtoupper($statements[$i]), 'DROP INDEX')
    )->max();

    expect($firstDropColumn)->not->toBeFalse();
    expect($lastDropIndex)->not->toBeNull()->toBeLessThan($firstDropColumn);
});

test('down() is guarded: running it twice does not fail', function (): void {
    $migration = externalReferenceMigration();

    $migration->down();
    $migration->down();

    expect(Schema::hasColumn('participants', 'external_id'))->toBeFalse();
});

test('down() then up() restores the columns and both partial indexes', function (): void {
    $migration = externalReferenceMigration();

    $migration->down();
    $migration->up();

    expect(Schema::hasColumn('participants', 'external_id'))->toBeTrue();
    expect(Schema::hasColumn('participants', 'source'))->toBeTrue();

    foreach (EXTERNAL_REFERENCE_INDEXES as $index => $definition) {
        expect(externalReferenceIndexDefinition($index))->toContain($definition);
        expect(externalReferenceIndexIsValid($index))->toBeTrue();
    }
});
