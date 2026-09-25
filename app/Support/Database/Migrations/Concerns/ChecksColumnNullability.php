<?php

declare(strict_types=1);

namespace App\Support\Database\Migrations\Concerns;

use Illuminate\Support\Facades\Schema;

/**
 * Shared by every idempotent, `$withinTransaction = false` migration that
 * needs to know whether a column it may still need to `->change()` to
 * `NOT NULL` has already been altered by an earlier, partially-completed
 * run (public-api step 5 review follow-up, item 11) —
 * `2026_09_24_130000_add_public_id_to_organizations_and_projects_tables`
 * and `2026_09_24_140000_add_public_api_fields_to_participants_table`
 * carried byte-for-byte identical copies of this one method before this
 * trait existed.
 *
 * Lives under `App\Support\…` rather than a dedicated `Database\Migrations\`
 * namespace: migrations are anonymous classes with no PSR-4 mapping of
 * their own in `composer.json`, and adding one is out of scope here (task
 * instruction: do not touch `composer.*`) — `App\` is already autoloaded,
 * and an anonymous migration class can `use` any autoloaded trait exactly
 * like an ordinary class.
 */
trait ChecksColumnNullability
{
    /**
     * `Schema::hasColumn()` only answers "does the column exist", not
     * "is it NOT NULL" — needed to decide whether a migration's own
     * `->change()` step (dropping `nullable()`) has already run on a rerun.
     *
     * `Schema::getColumns()` is declared to return
     * `list<array{name: string, ..., nullable: bool, ...}>` on the concrete
     * `Illuminate\Database\Schema\Builder` — but Larastan resolves the
     * `Schema` facade to a type PHPStan (`--level=max`) cannot trace that
     * docblock through, and reports each row as `mixed`. `is_array()` +
     * `is_string()` narrow it defensively instead of trusting the
     * (correct, just statically invisible) declared shape — the same
     * discipline `App\PublicApi\Serializers\InterviewSerializer`'s own raw
     * `DB::table()` row readers already apply.
     */
    private function columnIsNotNull(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $columnDefinition) {
            if (! is_array($columnDefinition)) {
                continue;
            }

            $name = $columnDefinition['name'] ?? null;

            if (! is_string($name) || $name !== $column) {
                continue;
            }

            return $columnDefinition['nullable'] === false;
        }

        return false;
    }
}
