<?php

declare(strict_types=1);

use App\Support\Database\Migrations\Concerns\RepairsInvalidUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable-link origin marker on `participants` (reusable-interview-links,
 * design AD-4): `reusable_interview_link_id` points a participant back at the
 * reusable link whose redemption created it. NULL for every other participant
 * (SSO exchange, M2M, operator entry link, and every row that existed before
 * this migration), so the column is nullable with no default and carries no
 * backfill.
 *
 * Adding a nullable column with no default is metadata-only in Postgres (no table
 * rewrite; it still takes a brief `ACCESS EXCLUSIVE` lock).
 *
 * Foreign key `participants_reusable_interview_link_id_foreign` is
 * `ON DELETE SET NULL`: a link is disabled softly and never deleted on purpose,
 * but if its row ever does disappear (an organisation or project hard-delete
 * cascades to `reusable_interview_links`) the visitors it produced must survive
 * as ordinary participants, never be deleted with it. It is added `NOT VALID` and
 * then validated in a SEPARATE statement: `ADD CONSTRAINT ... FOREIGN KEY` would
 * otherwise scan the whole hot table under a lock that blocks writes, and
 * `NOT VALID` skips that scan (new writes are enforced at once; the existing
 * rows are all NULL, so the validation cannot fail). Same split as
 * `participants_mode_check`. The two statements are each guarded, so a failure
 * between them leaves a constraint that a rerun validates rather than re-adds.
 *
 * One PARTIAL index `(organization_id, reusable_interview_link_id) WHERE
 * reusable_interview_link_id IS NOT NULL`, leading with `organization_id` (D22:
 * every query is tenant-scoped first). Partial because almost every row is NULL
 * and no query can use those entries: a full index would pay write amplification
 * and size on a hot table for nothing. Accepted cost: an organisation-leading
 * index cannot serve the foreign key's own `SET NULL` lookup, which only runs on
 * the rare cascade that removes a link.
 *
 * NOT unique: one link legitimately produces many visitors. No existing
 * `participants` constraint or index changes.
 *
 * `participants` is a hot path, so the index is built `CONCURRENTLY`
 * (`RepairsInvalidIndex::rebuildIndex()`), which Postgres refuses inside a
 * transaction block, hence `$withinTransaction = false`. That is also what makes
 * a partial-failure rerun possible, which only helps if every statement is
 * individually guarded: the column by `hasColumn()`, the foreign key by
 * `tableConstraintExists()` plus its `convalidated` flag, the index by
 * repair-first then `hasIndex()` (a concurrent build that failed partway leaves
 * the index present with `indisvalid = false`, which `hasIndex()` alone would
 * call done forever).
 *
 * Deploy order: this migration runs after `2026_10_01_100000` (the table it
 * references) and before the code that reads or writes the column serves
 * traffic. The previous release tolerates the new nullable column, so the
 * reverse window is safe.
 */
return new class extends Migration
{
    use RepairsInvalidUniqueIndex;

    public $withinTransaction = false;

    private const COLUMN = 'reusable_interview_link_id';

    private const FOREIGN_KEY = 'participants_reusable_interview_link_id_foreign';

    private const INDEX = 'participants_org_reusable_link_index';

    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table): void {
            if (! Schema::hasColumn('participants', self::COLUMN)) {
                $table->unsignedBigInteger(self::COLUMN)->nullable();
            }
        });

        $this->ensureForeignKey();
        $this->ensureIndex();
    }

    /**
     * Present and validated, in two statements. A constraint that exists but is
     * not validated (the validation was the statement that never ran) is
     * finished, not re-added.
     */
    private function ensureForeignKey(): void
    {
        if (! $this->tableConstraintExists('participants', self::FOREIGN_KEY)) {
            DB::statement(
                'ALTER TABLE participants ADD CONSTRAINT '.self::FOREIGN_KEY.'
                 FOREIGN KEY ('.self::COLUMN.') REFERENCES reusable_interview_links(id)
                 ON DELETE SET NULL NOT VALID'
            );
        }

        if (! $this->foreignKeyIsValidated()) {
            DB::statement('ALTER TABLE participants VALIDATE CONSTRAINT '.self::FOREIGN_KEY);
        }
    }

    /**
     * Queried directly against `pg_constraint`: `information_schema` has no
     * validated flag. `to_regclass()` (not a bare cast, which THROWS on a name
     * that does not exist) keeps the lookup total.
     */
    private function foreignKeyIsValidated(): bool
    {
        $row = DB::selectOne(
            'SELECT convalidated FROM pg_constraint WHERE conrelid = to_regclass(?) AND conname = ?',
            ['participants', self::FOREIGN_KEY]
        );

        return is_object($row) && isset($row->convalidated) && (bool) $row->convalidated;
    }

    /**
     * Repair first, then presence: the invalid-index check must run ahead of
     * the `hasIndex()` early return, or an index Postgres marked invalid would
     * be reported "done" forever. An invalid one is dropped and rebuilt by the
     * repair itself; an absent or valid one falls through to the guard.
     */
    private function ensureIndex(): void
    {
        $columns = ['organization_id', self::COLUMN];
        $where = self::COLUMN.' IS NOT NULL';

        $this->repairInvalidIndex('participants', $columns, self::INDEX, $where);

        if (Schema::hasIndex('participants', self::INDEX)) {
            return;
        }

        $this->rebuildIndex('participants', $columns, self::INDEX, $where);
    }

    /**
     * Index, then constraint, then column: dropping the column would drop both
     * anyway, but through a lock-heavier path than the `CONCURRENTLY` index drop.
     * Every step is guarded so `down()` is rerunnable after a partial failure.
     * Visitors already created survive as ordinary participants without the
     * marker; the marker values themselves are lost (the origin is
     * informational, nothing authenticates on it).
     */
    public function down(): void
    {
        $this->dropIndex(self::INDEX);

        DB::statement('ALTER TABLE participants DROP CONSTRAINT IF EXISTS '.self::FOREIGN_KEY);

        if (Schema::hasColumn('participants', self::COLUMN)) {
            Schema::table('participants', function (Blueprint $table): void {
                $table->dropColumn(self::COLUMN);
            });
        }
    }
};
