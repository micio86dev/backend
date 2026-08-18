<?php

declare(strict_types=1);

/**
 * RED — CatalogMeta::bump() must be immune to the `catalog_meta_id_seq`
 * position.
 *
 * `id` is not in CatalogMeta::$fillable, so `firstOrCreate(['id' => 1], ...)`
 * silently drops the `id` attribute on the create path (mass-assignment
 * protection). Postgres then assigns the id from the table's sequence
 * instead of forcing 1. Whenever that sequence is not sitting at 1 — which
 * happens the moment ANY row has ever been inserted into `catalog_meta`
 * before this call — `bump()` creates a second row instead of reusing the
 * singleton, and the revision counter gets stuck at 1 forever (every future
 * bump() looks for id=1, never finds it, creates yet another throwaway row).
 *
 * The sequence is advanced explicitly here (not by relying on suite
 * ordering) so this test fails for the stated reason regardless of what ran
 * before it.
 */

use App\Models\CatalogMeta;
use Illuminate\Support\Facades\DB;

test('bump() keeps exactly one row at id=1 with a monotonic revision even when the id sequence has advanced', function (): void {
    // Advance the `catalog_meta_id_seq` sequence past 1, independently of
    // test ordering and without inserting any row (a real production
    // sequence can drift this way from ANY prior insert/delete on the
    // table, regardless of how this test drives it).
    DB::statement("SELECT setval(pg_get_serial_sequence('catalog_meta', 'id'), 55)");

    // The sequence must now be sitting above 1.
    $nextId = DB::selectOne("SELECT nextval(pg_get_serial_sequence('catalog_meta', 'id')) AS next_id")->next_id;
    expect($nextId)->toBeGreaterThan(1);

    CatalogMeta::bump();
    CatalogMeta::bump();
    CatalogMeta::bump();

    $rows = CatalogMeta::all();

    // 1. Exactly one row, and its id is 1 — the singleton invariant.
    expect($rows)->toHaveCount(1);
    expect($rows->first()->id)->toBe(1);

    // 2. N bumps produce revision N — monotonic, not stuck.
    expect($rows->first()->revision)->toBe(3);
});
