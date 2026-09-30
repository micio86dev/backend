<?php

declare(strict_types=1);

use App\Support\Database\Migrations\Concerns\RepairsInvalidIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candidate external reference on `participants` (candidate-external-reference,
 * design AD-4): the calling system's own integer id for a candidate
 * (`external_id`) and the name of the system it came from (`source`), both
 * nullable and independent.
 *
 * `external_id` is `BIGINT`, not `INTEGER`: the application caps it at 2^53-1
 * (JS `Number.MAX_SAFE_INTEGER`, see `App\Support\Participant\ExternalReference`)
 * and external systems routinely use 64-bit keys. `source` is `VARCHAR(180)`,
 * matching the validation cap. Adding a nullable column with no default is
 * metadata-only in Postgres, so it does not rewrite the table (it still takes
 * a brief `ACCESS EXCLUSIVE` lock).
 *
 * NOT unique, and no CHECK coupling the pair: a `participants` row is an
 * enrolment, so the same `(source, external_id)` legitimately repeats across
 * projects and organisations, and an enrolment may carry only one of the two.
 *
 * Two PARTIAL composite indexes, both leading with `organization_id` (D22:
 * every query is tenant-scoped first):
 *   - `(organization_id, source, external_id) WHERE source IS NOT NULL`
 *     serves `source = ?` and `source = ? AND external_id = ?`
 *   - `(organization_id, external_id) WHERE external_id IS NOT NULL`
 *     serves `external_id = ?` on its own
 * Partial because most rows (every backoffice- or SSO-created candidate) are
 * null in both columns: a full index would pay write amplification and size on
 * a hot table for entries no query can use, and every query shape above
 * implies the predicate, so the planner can still use them.
 *
 * `participants` is a hot path, so the indexes are built `CONCURRENTLY`
 * (`RepairsInvalidIndex::rebuildIndex()`), which Postgres refuses inside a
 * transaction block — hence `$withinTransaction = false`. That is also what
 * makes a partial-failure rerun possible, which only helps if every statement
 * is individually guarded: each column by `hasColumn()`, each index by
 * `ensureIndex()`. `hasIndex()` only checks presence by name, so a concurrent
 * build that failed partway (index present, `indisvalid = false`) is repaired
 * first; the repair lives in this migration from day one, unlike the public-id
 * index, whose standalone repair migration only exists because that one
 * shipped without it.
 *
 * Deploy order: this migration must run before the code that reads or writes
 * the columns serves traffic. The previous release tolerates the new nullable
 * columns, so the reverse window is safe.
 */
return new class extends Migration
{
    use RepairsInvalidIndex;

    public $withinTransaction = false;

    private const SOURCE_INDEX = 'participants_org_source_external_id_index';

    private const EXTERNAL_ID_INDEX = 'participants_org_external_id_index';

    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table): void {
            if (! Schema::hasColumn('participants', 'external_id')) {
                $table->bigInteger('external_id')->nullable();
            }

            if (! Schema::hasColumn('participants', 'source')) {
                $table->string('source', 180)->nullable();
            }
        });

        $this->ensureIndex(self::SOURCE_INDEX, ['organization_id', 'source', 'external_id'], 'source IS NOT NULL');
        $this->ensureIndex(self::EXTERNAL_ID_INDEX, ['organization_id', 'external_id'], 'external_id IS NOT NULL');
    }

    /**
     * Repair first, then presence: the invalid-index check must run ahead of
     * the `hasIndex()` early return, or an index Postgres marked invalid would
     * be reported "done" forever. An invalid one is dropped and rebuilt by the
     * repair itself; an absent or valid one falls through to the guard.
     *
     * @param  list<string>  $columns
     */
    private function ensureIndex(string $indexName, array $columns, string $where): void
    {
        $this->repairInvalidIndex('participants', $columns, $indexName, $where);

        if (Schema::hasIndex('participants', $indexName)) {
            return;
        }

        $this->rebuildIndex('participants', $columns, $indexName, $where);
    }

    /**
     * Indexes first, then columns: dropping a column would drop the indexes
     * that use it anyway, but through a lock-heavier path than the
     * `CONCURRENTLY` drop. Every step is guarded so `down()` is rerunnable
     * after a partial failure. Stored values are lost (acceptable: they are
     * the calling system's own data and can be re-sent).
     */
    public function down(): void
    {
        $this->dropIndex(self::SOURCE_INDEX);
        $this->dropIndex(self::EXTERNAL_ID_INDEX);

        $columns = array_values(array_filter(
            ['external_id', 'source'],
            static fn (string $column): bool => Schema::hasColumn('participants', $column),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('participants', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }
};
