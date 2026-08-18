<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enforce the `catalog_meta` singleton invariant (id=1) at the database
 * level, not merely by convention.
 *
 * Pre-existing defect (fixed alongside this migration in
 * App\Models\CatalogMeta::bump()): `id` was missing from $fillable, so
 * `firstOrCreate(['id' => 1], ...)` silently dropped it on the create path.
 * Whenever the table's id sequence was not sitting at 1 — which happens the
 * moment ANY row was ever inserted into `catalog_meta` before a given
 * bump() call — Postgres assigned the id from the sequence instead, and
 * bump() created a brand-new row rather than reusing the singleton. A
 * production table may therefore ALREADY contain more than one row.
 *
 * Production safety: before adding the CHECK constraint, this migration
 * consolidates any existing duplicate rows so the constraint can never be
 * violated by data that already exists.
 *   - If more than one row exists, the row with the HIGHEST revision is
 *     kept (this is the row that was bumped closest to correctness — bumps
 *     are monotonic on each individual row, so picking the max never moves
 *     the observable revision backwards for any consumer). All other rows
 *     are deleted, and the kept row is given both id=1 and the max revision
 *     across all deleted rows (in case the row with the highest revision
 *     was not itself id=1).
 *   - If exactly one row exists and its id is not 1, it is renumbered to 1.
 *   - If zero rows exist, there is nothing to consolidate.
 * This makes the migration idempotent and safe to run against a table that
 * already has duplicates, a table that already has a single correct row, or
 * an empty table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $rows = DB::table('catalog_meta')->orderByDesc('revision')->orderBy('id')->get();

            if ($rows->count() > 1) {
                $canonical = $rows->first();
                $maxRevision = (int) $rows->max('revision');

                DB::table('catalog_meta')->where('id', '!=', $canonical->id)->delete();

                if ((int) $canonical->id !== 1) {
                    // The id=1 slot is guaranteed free here: whatever row held it
                    // before was in the deleted set above.
                    DB::table('catalog_meta')->where('id', $canonical->id)->update(['id' => 1]);
                }

                DB::table('catalog_meta')->where('id', 1)->update(['revision' => $maxRevision]);
            } elseif ($rows->count() === 1 && (int) $rows->first()->id !== 1) {
                DB::table('catalog_meta')->where('id', $rows->first()->id)->update(['id' => 1]);
            }
        });

        DB::statement(
            'ALTER TABLE catalog_meta ADD CONSTRAINT catalog_meta_singleton_id_check CHECK (id = 1)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE catalog_meta DROP CONSTRAINT IF EXISTS catalog_meta_singleton_id_check');
    }
};
