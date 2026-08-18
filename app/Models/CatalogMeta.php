<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CatalogMeta singleton model (C3 Framework Catalog).
 *
 * Single-row cache-busting counter. `bump()` enforces id=1 by explicit
 * find-then-create (see below), and a DB-level CHECK constraint
 * (`catalog_meta_singleton_id_check`, added by the
 * 2026_08_18_000000_add_catalog_meta_singleton_check migration) rejects any
 * row whose id is not 1, so the singleton invariant is enforced by the
 * database, not merely assumed.
 * revision: monotonic int bumped by the seeder on structural changes.
 * Used for "catalog changed" signalling — NOT a per-version discriminator.
 *
 * NOTE: Not tenant-scoped. Global singleton.
 *
 * @property int $revision
 */
class CatalogMeta extends Model
{
    /**
     * Explicit table name — 'catalog_meta' (not the Laravel-pluralized 'catalog_metas').
     */
    protected $table = 'catalog_meta';

    /**
     * @var list<string>
     *
     * `id` is deliberately NOT here: it is an internal invariant (always 1),
     * not user-supplied data, so it must never become mass-assignable.
     * `bump()` sets it via forceFill() instead — see below.
     */
    protected $fillable = ['revision'];

    /**
     * Bump the catalog revision counter.
     *
     * Ensures the singleton row (id=1) exists and increments its revision.
     * Safe to call multiple times — idempotent creation, monotonic increment.
     *
     * Explicit find-then-create instead of `firstOrCreate(['id' => 1], ...)`:
     * `id` is not in $fillable, so firstOrCreate's create path would silently
     * drop it via mass-assignment protection, letting Postgres assign the id
     * from the table sequence instead of forcing 1. Whenever that sequence
     * is not sitting at 1 (i.e. after any prior insert/delete anywhere in
     * `catalog_meta`), bump() would then create a NEW row every time instead
     * of reusing the singleton, permanently losing the revision count.
     * forceFill() bypasses mass-assignment protection ONLY here, for this
     * one internal invariant — it does not widen $fillable, so `id` still
     * cannot be set from user input anywhere else.
     */
    public static function bump(): void
    {
        $row = self::query()->find(1);

        if ($row === null) {
            // `new self`, not `new static`: this is a singleton keyed on a
            // literal id of 1, so a subclass pointed at the same row would be
            // the bug, not a use case. PHPStan flags `new static()` on a
            // non-final class for exactly that reason.
            $row = new self;
            $row->forceFill(['id' => 1, 'revision' => 0]);
            $row->save();
        }

        $row->increment('revision');
    }
}
