<?php

declare(strict_types=1);

use App\Support\Database\Migrations\Concerns\RepairsInvalidUniqueIndex;
use Illuminate\Database\Migrations\Migration;

/**
 * Standalone repair for `participants_public_id_unique` (public-api step 6
 * review follow-up, finding 2).
 *
 * `2026_09_24_140000_add_public_api_fields_to_participants_table::
 * addPublicIdUniqueIndex()` already detects and repairs a
 * `CREATE UNIQUE INDEX CONCURRENTLY` build that failed partway (Postgres
 * marks it `pg_index.indisvalid = false` rather than removing it) — but
 * Laravel never re-runs a migration whose row is already recorded in
 * `migrations`. An environment that ran the 140000 migration BEFORE that
 * invalid-index handling was added is stuck: its `up()` already executed
 * once, so it never executes again, and the repair it now contains never
 * runs where it is actually needed.
 *
 * This migration is that repair, separately and safely runnable on its
 * own: it does ONLY the pg_index check-and-rebuild
 * (`RepairsInvalidUniqueIndex::repairInvalidUniqueIndex()`, the identical
 * logic the 140000 migration itself now calls — see that migration for the
 * full reasoning), nothing else. It is a no-op both when the index is
 * already valid and when it is absent entirely (an environment that has
 * never run 140000 at all, or one where 140000 already runs the current,
 * fixed `up()` for the first time) — `repairInvalidUniqueIndex()` only acts
 * when Postgres has marked the index invalid.
 *
 * `$withinTransaction = false` for the identical reason the 140000
 * migration disables it: a real repair must be able to issue
 * `CREATE UNIQUE INDEX CONCURRENTLY`, which Postgres refuses inside a
 * transaction block.
 */
return new class extends Migration
{
    use RepairsInvalidUniqueIndex;

    public $withinTransaction = false;

    public function up(): void
    {
        $this->repairInvalidUniqueIndex('participants', 'public_id', 'participants_public_id_unique');
    }

    /**
     * Deliberately a no-op. This migration only repairs an existing index
     * in place — it never created `participants_public_id_unique` (the
     * 140000 migration owns that), so it has nothing of its own to drop.
     */
    public function down(): void {}
};
