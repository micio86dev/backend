<?php

declare(strict_types=1);

/**
 * gga round 4 finding 3: both `public_id`-adding migrations disable the
 * wrapping transaction (`$withinTransaction = false`), which is exactly
 * what makes a partial-failure rerun possible in the first place — but that
 * only helps if every schema-changing statement inside `up()` is ALSO
 * individually guarded, or the second run fails on the first `ADD COLUMN`/
 * `ADD CONSTRAINT` of a step the first run already completed. Proven here
 * by calling the SAME anonymous migration class's `up()` a second time,
 * directly, right after `RefreshDatabase` already ran every migration once.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('the step 4 public_id migration (organizations/projects) is idempotent on rerun', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_24_130000_add_public_id_to_organizations_and_projects_tables.php');

    $migration->up();

    expect(Schema::hasColumn('organizations', 'public_id'))->toBeTrue();
    expect(Schema::hasColumn('projects', 'public_id'))->toBeTrue();
    expect(Schema::hasIndex('organizations', 'organizations_public_id_unique'))->toBeTrue();
    expect(Schema::hasIndex('projects', 'projects_public_id_unique'))->toBeTrue();
});

test('the step 5 participants public-api-fields migration is idempotent on rerun', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_24_140000_add_public_api_fields_to_participants_table.php');

    $migration->up();

    expect(Schema::hasColumn('participants', 'public_id'))->toBeTrue();
    expect(Schema::hasColumn('participants', 'metadata'))->toBeTrue();
    expect(Schema::hasColumn('participants', 'exit_redirect_url'))->toBeTrue();
    expect(Schema::hasColumn('participants', 'mode'))->toBeTrue();
    expect(Schema::hasColumn('participants', 'session_token_jti'))->toBeTrue();
    expect(Schema::hasIndex('participants', 'participants_public_id_unique'))->toBeTrue();

    $constraintExists = DB::select(
        "SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'participants_mode_check'"
    );
    expect($constraintExists)->not->toBeEmpty();
});

test('the participants unique index and mode check are rebuilt with lock-minimising DDL when reapplied', function (): void {
    // Drop just the index and the constraint this migration owns (columns
    // stay put), then call up() again from inside this test's own wrapping
    // transaction (Feature/Migration is RefreshDatabase-scoped — see
    // tests/Pest.php) — the exact condition `addPublicIdUniqueIndex()`'s own
    // docblock names as the one that must fall back to the plain blueprint
    // form rather than `CREATE INDEX CONCURRENTLY`, which Postgres refuses
    // outright inside an open transaction.
    // DROP INDEX, not Schema::table()->dropUnique() — the index reaching
    // this point may have been built by CREATE UNIQUE INDEX CONCURRENTLY
    // (the bootstrap migration run, outside any transaction) rather than
    // an ADD CONSTRAINT ... UNIQUE the blueprint form assumes, and
    // dropUnique() only knows how to drop the latter.
    DB::statement('DROP INDEX IF EXISTS participants_public_id_unique');
    DB::statement('ALTER TABLE participants DROP CONSTRAINT participants_mode_check');

    expect(DB::transactionLevel())->toBeGreaterThan(0);

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_24_140000_add_public_api_fields_to_participants_table.php');
    $migration->up();

    expect(Schema::hasIndex('participants', 'participants_public_id_unique'))->toBeTrue();

    $constraintExists = DB::select(
        "SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'participants_mode_check'"
    );
    expect($constraintExists)->not->toBeEmpty();

    // Inside a transaction, CONCURRENTLY is never attempted — Postgres would
    // have refused it and this test would have errored, not merely failed
    // an assertion.
    expect(collect($statements)->contains(fn (string $sql): bool => str_contains(strtoupper($sql), 'CONCURRENTLY')))
        ->toBeFalse();

    // The CHECK constraint is added NOT VALID, then validated separately —
    // both statements are ordinary transactional DDL, unlike the index.
    expect(collect($statements)->contains(fn (string $sql): bool => str_contains(strtoupper($sql), 'NOT VALID')))
        ->toBeTrue();
    expect(collect($statements)->contains(fn (string $sql): bool => str_contains(strtoupper($sql), 'VALIDATE CONSTRAINT')))
        ->toBeTrue();
});

test('step 6, Part A item 2: an INVALID participants_public_id_unique index (a failed CONCURRENTLY build) is dropped and rebuilt, not left in place', function (): void {
    // Schema::hasIndex() reports an index as present purely by NAME — it has
    // no idea Postgres itself considers this one broken. A CREATE UNIQUE
    // INDEX CONCURRENTLY that failed partway (e.g. the migration process was
    // killed) leaves EXACTLY this shape: the index exists, by that name, but
    // pg_index.indisvalid is false and it enforces nothing.
    DB::statement('UPDATE pg_index SET indisvalid = false WHERE indexrelid = \'participants_public_id_unique\'::regclass');

    $invalidBefore = DB::selectOne(
        "SELECT indisvalid FROM pg_index WHERE indexrelid = 'participants_public_id_unique'::regclass"
    );
    expect($invalidBefore)->not->toBeNull();
    expect((bool) $invalidBefore->indisvalid)->toBeFalse();
    expect(Schema::hasIndex('participants', 'participants_public_id_unique'))->toBeTrue();

    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_24_140000_add_public_api_fields_to_participants_table.php');
    $migration->up();

    $validAfter = DB::selectOne(
        "SELECT indisvalid FROM pg_index WHERE indexrelid = 'participants_public_id_unique'::regclass"
    );
    expect($validAfter)->not->toBeNull();
    expect((bool) $validAfter->indisvalid)->toBeTrue();
});
