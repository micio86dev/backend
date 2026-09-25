<?php

declare(strict_types=1);

/**
 * `2026_09_24_150100_repair_invalid_participants_public_id_index` (public-api
 * step 6 review follow-up, finding 2) — a standalone, separately-runnable
 * repair for an environment that already ran the 140000 migration BEFORE
 * its invalid-index handling existed, and therefore never re-executes its
 * `up()`.
 *
 * Asserts the repair migration:
 * - repairs (drops + rebuilds) an INVALID `participants_public_id_unique`;
 * - is a no-op when the index is already valid;
 * - is a no-op when the index is absent entirely.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function loadRepairMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_24_150100_repair_invalid_participants_public_id_index.php');

    return $migration;
}

test('repairs an INVALID participants_public_id_unique index', function (): void {
    DB::statement("UPDATE pg_index SET indisvalid = false WHERE indexrelid = 'participants_public_id_unique'::regclass");

    $before = DB::selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = 'participants_public_id_unique'::regclass");
    expect($before)->not->toBeNull();
    expect((bool) $before->indisvalid)->toBeFalse();

    loadRepairMigration()->up();

    $after = DB::selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = 'participants_public_id_unique'::regclass");
    expect($after)->not->toBeNull();
    expect((bool) $after->indisvalid)->toBeTrue();
    expect(Schema::hasIndex('participants', 'participants_public_id_unique'))->toBeTrue();
});

test('is a no-op when participants_public_id_unique is already valid', function (): void {
    $before = DB::selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = 'participants_public_id_unique'::regclass");
    expect($before)->not->toBeNull();
    expect((bool) $before->indisvalid)->toBeTrue();

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    loadRepairMigration()->up();

    // No DDL at all — a valid index is left untouched, not dropped and
    // rebuilt for no reason.
    expect(collect($statements)->contains(fn (string $sql): bool => str_contains(strtoupper($sql), 'DROP INDEX')
        || str_contains(strtoupper($sql), 'DROP CONSTRAINT')
        || str_contains(strtoupper($sql), 'CREATE UNIQUE INDEX')))
        ->toBeFalse();

    expect(Schema::hasIndex('participants', 'participants_public_id_unique'))->toBeTrue();
});

test('is a no-op when participants_public_id_unique is absent entirely', function (): void {
    DB::statement('DROP INDEX IF EXISTS participants_public_id_unique');
    expect(Schema::hasIndex('participants', 'participants_public_id_unique'))->toBeFalse();

    loadRepairMigration()->up();

    // Absent stays absent — repairing is not the same job as creating,
    // which the 140000 migration alone owns.
    expect(Schema::hasIndex('participants', 'participants_public_id_unique'))->toBeFalse();
});

test('down() is a no-op — this migration never created the index it repairs', function (): void {
    expect(Schema::hasIndex('participants', 'participants_public_id_unique'))->toBeTrue();

    loadRepairMigration()->down();

    expect(Schema::hasIndex('participants', 'participants_public_id_unique'))->toBeTrue();
});
