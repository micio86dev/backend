<?php

declare(strict_types=1);

namespace App\Support\Database\Migrations\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared by every migration that owns a Postgres unique index built with
 * `CREATE UNIQUE INDEX CONCURRENTLY` and therefore needs to detect and
 * repair a build that failed partway (public-api step 6 review follow-up,
 * finding 2). `Schema::hasIndex()` only ever checks catalogue PRESENCE by
 * name — a `CONCURRENTLY` build killed mid-run, or one that hit a
 * conflicting row during its concurrent scan, leaves EXACTLY this shape:
 * the index exists, by that name, but Postgres marks
 * `pg_index.indisvalid = false` and it enforces nothing. `hasIndex()` alone
 * would report "done" forever.
 *
 * Extracted from `2026_09_24_140000_add_public_api_fields_to_participants_table`
 * (the only prior owner of this logic) so
 * `2026_09_24_150100_repair_invalid_participants_public_id_index` — a
 * standalone, separately-runnable repair for an environment that already
 * ran the 140000 migration BEFORE this invalid-index handling existed, and
 * therefore never re-executes its `up()` — can reuse the identical
 * check-and-rebuild without a second, drifting copy.
 */
trait RepairsInvalidUniqueIndex
{
    /**
     * Queried directly against `pg_index` — Laravel's `Schema` facade has no
     * `hasValidIndex()` equivalent. `to_regclass()` (not a bare cast, which
     * THROWS on a name that does not exist yet) resolves to `NULL` for an
     * unknown relation, which short-circuits this query to no rows — the
     * same as "not invalid" for an index that has never been created.
     */
    private function uniqueIndexIsInvalid(string $indexName): bool
    {
        $row = DB::selectOne(
            'SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)',
            [$indexName]
        );

        // `DB::selectOne()` is declared `@return mixed` — narrowed here
        // (PHPStan `--level=max`'s explicit-mixed checking) with
        // `is_object()` + `isset()` rather than an `@var`/`assert()`
        // override, so both branches are genuinely provable from the
        // variable's own runtime shape.
        if (! is_object($row) || ! isset($row->indisvalid)) {
            return false;
        }

        return ! (bool) $row->indisvalid;
    }

    /**
     * No Laravel-native `Schema::hasCheckConstraint()` equivalent exists —
     * queried directly against `information_schema.table_constraints`.
     * Filtered by `table_name` AND `table_schema` (not `constraint_name`
     * alone, which is only unique WITHIN a schema+table): a same-named
     * constraint on an unrelated table would otherwise report a false
     * positive. `current_schema()` — not a hardcoded `'public'` — matches
     * whatever schema this connection actually targets.
     */
    private function tableConstraintExists(string $table, string $constraintName): bool
    {
        $rows = DB::select(
            'SELECT 1 FROM information_schema.table_constraints
             WHERE constraint_name = ? AND table_name = ? AND table_schema = current_schema()',
            [$constraintName, $table],
        );

        return $rows !== [];
    }

    /**
     * Drops the named unique index/constraint whichever shape it is
     * currently backed by: a `CREATE UNIQUE INDEX CONCURRENTLY` build
     * leaves a bare index, while the in-transaction blueprint fallback
     * (see `rebuildUniqueIndex()`) leaves a CONSTRAINT-backed one, and
     * dropping the wrong statement form for either fails.
     */
    private function dropUniqueIndexOrConstraint(string $table, string $indexName): void
    {
        if ($this->tableConstraintExists($table, $indexName)) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$indexName}");
        } else {
            DB::statement("DROP INDEX IF EXISTS {$indexName}");
        }
    }

    /**
     * `participants`-style tables are a hot path — the default
     * `ADD CONSTRAINT ... UNIQUE` Laravel's `unique()` blueprint method
     * issues takes an `ACCESS EXCLUSIVE` lock (blocking every concurrent
     * read AND write) for as long as it takes Postgres to build the index
     * over the whole table. `CREATE UNIQUE INDEX CONCURRENTLY` builds it
     * without that lock, at the cost of needing its own, non-transactional
     * DDL statement — Postgres refuses it outright
     * (`CREATE INDEX CONCURRENTLY cannot run inside a transaction block`)
     * whenever one is already open, which is exactly the case inside a
     * `RefreshDatabase`-wrapped test; the plain blueprint form is used
     * there instead, since the only thing that matters inside a test's
     * own short-lived, already-isolated transaction is that the index
     * ends up existing, never lock duration.
     */
    private function rebuildUniqueIndex(string $table, string $column, string $indexName): void
    {
        if (DB::transactionLevel() > 0) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $indexName): void {
                $blueprint->unique($column, $indexName);
            });

            return;
        }

        DB::statement(
            "CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS {$indexName} ON {$table} ({$column})"
        );
    }

    /**
     * The repair itself: a no-op when the index is already valid, or absent
     * entirely (nothing to repair — the migration that owns creating it is
     * responsible for that, not this trait). Only when Postgres has marked
     * it `indisvalid = false` does this drop and rebuild it.
     */
    private function repairInvalidUniqueIndex(string $table, string $column, string $indexName): void
    {
        if (! $this->uniqueIndexIsInvalid($indexName)) {
            return;
        }

        $this->dropUniqueIndexOrConstraint($table, $indexName);
        $this->rebuildUniqueIndex($table, $column, $indexName);
    }
}
