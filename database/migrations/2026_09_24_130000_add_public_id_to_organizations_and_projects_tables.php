<?php

declare(strict_types=1);

use App\Support\Database\Migrations\Concerns\ChecksColumnNullability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * `public_id` — the BEAI Public API (`/v1`) external identifier for
 * `organizations` and `projects` (public-api step 4, G-05).
 *
 * `char(26)` stores ONLY the bare Crockford-base32 ULID — no prefix. The
 * prefix (`org_`/`prj_`) is presentational: `App\Support\PublicApi\PublicId`
 * prepends it on the way out and strips/validates it on the way in
 * (`decode()`), which is also why a mismatched prefix on a syntactically
 * valid ULID never matches a row here and answers `404`, never `400`
 * (SPEC.md §3.2 "Path parameters are validated by regex; mismatched prefix
 * → 404").
 *
 * Existing rows are backfilled in PHP, in chunks, rather than via a single
 * DB-side expression: PostgreSQL has no built-in ULID generator, and a
 * PHP-side `Str::ulid()` per row keeps this migration honest about actually
 * producing lexicographically-sortable, cryptographically-unpredictable
 * ids identical in shape to the ones new rows get from
 * `App\Models\Concerns\HasPublicId`'s `creating` hook — rather than a
 * cheaper-but-different scheme (e.g. a zero-padded sequence) that would
 * only coincidentally satisfy the same UNIQUE/char(26) constraint.
 *
 * The column is added NULLABLE first, backfilled, THEN altered to NOT NULL
 * — the only way to introduce a NOT NULL column on tables that already have
 * rows without a placeholder value existing transiently.
 *
 * `$withinTransaction = false` (public-api step 5, Part A follow-up 1):
 * Laravel wraps a migration's `up()` in ONE transaction by default on
 * PostgreSQL. With that default, every per-chunk `UPDATE` below AND the
 * closing `ALTER TABLE ... SET NOT NULL` + `ADD UNIQUE` all ran inside that
 * SAME transaction, so the ACCESS EXCLUSIVE lock the two `ALTER TABLE`
 * statements take was held for the FULL DURATION of the backfill loop, not
 * just its own brief moment — on a large table, that is a long write-blocking
 * lock, exactly what the chunked backfill exists to avoid. Disabling the
 * wrapper lets each chunk's `UPDATE`s commit independently, and confines the
 * exclusive lock to just the two closing `ALTER TABLE` statements.
 *
 * TRUE idempotency (gga round 4 finding 3 — the code, not just this
 * docblock's earlier claim): with `$withinTransaction = false`, a re-run
 * after a partial failure (crash, deploy interrupted, `migrate` re-invoked)
 * starts `up()` from the top again — `foreach (self::TABLES ...)` re-enters
 * a table whose column the FIRST attempt already added. Every schema-
 * changing statement below is therefore individually guarded (`hasColumn()`
 * before adding the column or altering it NOT NULL, `hasIndex()` before
 * adding the unique constraint) so a step already applied is skipped rather
 * than re-attempted — `ALTER TABLE ... ADD COLUMN` on a column that already
 * exists is a hard Postgres error, not a silent no-op, so without these
 * guards a rerun would fail on the very first statement of a table the
 * first attempt had already fully or partially migrated. `backfill()`'s own
 * `whereNull('public_id')` guard (still load-bearing) makes the row-level
 * backfill idempotent the same way.
 */
return new class extends Migration
{
    use ChecksColumnNullability;

    public $withinTransaction = false;

    private const TABLES = ['organizations', 'projects'];

    private const CHUNK_SIZE = 500;

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'public_id')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->char('public_id', 26)->nullable()->after('id');
                });
            }

            $this->backfill($table);

            if (! $this->columnIsNotNull($table, 'public_id')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->char('public_id', 26)->nullable(false)->change();
                });
            }

            if (! Schema::hasIndex($table, $table.'_public_id_unique')) {
                Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                    $blueprint->unique('public_id', $table.'_public_id_unique');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropUnique($table.'_public_id_unique');
                $blueprint->dropColumn('public_id');
            });
        }
    }

    private function backfill(string $table): void
    {
        // `whereNull('public_id')` makes a re-run after a partial failure
        // resume rather than re-minting a fresh ulid for a row a prior,
        // interrupted run already backfilled — load-bearing now that
        // `$withinTransaction = false` (see this class's own docblock)
        // means each chunk's `UPDATE`s commit independently instead of
        // rolling back together with the rest of the migration. A no-op
        // (0 rows) when every row already has one — e.g. the column was
        // already NOT NULL on a rerun and this table has nothing left to
        // backfill.
        DB::table($table)->whereNull('public_id')->orderBy('id')->chunkById(self::CHUNK_SIZE, function (Collection $rows) use ($table): void {
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->update([
                    'public_id' => (string) Str::ulid(),
                ]);
            }
        });
    }
};
