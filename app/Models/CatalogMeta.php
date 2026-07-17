<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CatalogMeta singleton model (C3 Framework Catalog).
 *
 * Single-row cache-busting counter. The table always has exactly one row (id=1).
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
     * @var list<string>
     */
    protected $fillable = ['revision'];

    /**
     * Bump the catalog revision counter.
     *
     * Ensures the singleton row (id=1) exists and increments its revision.
     * Safe to call multiple times — idempotent creation, monotonic increment.
     */
    public static function bump(): void
    {
        static::firstOrCreate(['id' => 1], ['revision' => 0])->increment('revision');
    }
}
