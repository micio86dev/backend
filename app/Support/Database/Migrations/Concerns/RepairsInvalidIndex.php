<?php

declare(strict_types=1);

namespace App\Support\Database\Migrations\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Generic, non-unique counterpart of `RepairsInvalidUniqueIndex`: detects and
 * repairs a Postgres index built with `CREATE INDEX CONCURRENTLY` that failed
 * partway, for any (composite, optionally partial) index on a hot table.
 *
 * `Schema::hasIndex()` only checks catalogue PRESENCE by name. A concurrent
 * build that was killed mid-run, or that hit a conflicting row during its
 * scan, leaves the index in the catalogue with `pg_index.indisvalid = false`:
 * Postgres never uses it, but `hasIndex()` keeps reporting "done" and a rerun
 * of the owning migration would never rebuild it.
 *
 * `RepairsInvalidUniqueIndex` delegates its validity query here, so there is
 * exactly one copy of the `pg_index` lookup.
 */
trait RepairsInvalidIndex
{
    /**
     * Queried directly against `pg_index` — Laravel's `Schema` facade has no
     * `hasValidIndex()` equivalent. `to_regclass()` (not a bare cast, which
     * THROWS on a name that does not exist yet) resolves to `NULL` for an
     * unknown relation, which short-circuits this query to no rows — the
     * same as "not invalid" for an index that has never been created.
     */
    private function indexIsInvalid(string $indexName): bool
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
     * `CREATE INDEX CONCURRENTLY` builds the index without the lock that
     * blocks every concurrent read AND write, at the cost of needing its own
     * non-transactional statement: Postgres refuses it outright inside a
     * transaction block. A real `php artisan migrate` of a migration with
     * `$withinTransaction = false` never opens one, so `CONCURRENTLY` always
     * applies in production; inside a `RefreshDatabase`-wrapped test the
     * plain `CREATE INDEX` is used instead — the only thing that matters in a
     * test's own short-lived, already-isolated transaction is that the index
     * ends up existing, never lock duration. Plain `CREATE INDEX IF NOT
     * EXISTS` is legal in a transaction and carries the same `WHERE`.
     *
     * @param  list<string>  $columns
     * @param  string|null  $where  predicate of a partial index, without the `WHERE` keyword
     */
    private function rebuildIndex(string $table, array $columns, string $indexName, ?string $where = null): void
    {
        $concurrently = DB::transactionLevel() > 0 ? '' : ' CONCURRENTLY';
        $columnList = implode(', ', $columns);
        $predicate = $where === null ? '' : " WHERE {$where}";

        DB::statement(
            "CREATE INDEX{$concurrently} IF NOT EXISTS {$indexName} ON {$table} ({$columnList}){$predicate}"
        );
    }

    /**
     * `DROP INDEX CONCURRENTLY` for the same reason as the build: a plain
     * `DROP INDEX` takes an `ACCESS EXCLUSIVE` lock on the table. It cannot
     * run inside a transaction either, so the same `transactionLevel()`
     * branch applies.
     */
    private function dropIndex(string $indexName): void
    {
        $concurrently = DB::transactionLevel() > 0 ? '' : ' CONCURRENTLY';

        DB::statement("DROP INDEX{$concurrently} IF EXISTS {$indexName}");
    }

    /**
     * The repair itself: a no-op when the index is already valid, or absent
     * entirely (nothing to repair — the migration that owns creating it is
     * responsible for that, not this trait). Only when Postgres has marked it
     * `indisvalid = false` does this drop and rebuild it.
     *
     * @param  list<string>  $columns
     */
    private function repairInvalidIndex(string $table, array $columns, string $indexName, ?string $where = null): void
    {
        if (! $this->indexIsInvalid($indexName)) {
            return;
        }

        $this->dropIndex($indexName);
        $this->rebuildIndex($table, $columns, $indexName, $where);
    }
}
