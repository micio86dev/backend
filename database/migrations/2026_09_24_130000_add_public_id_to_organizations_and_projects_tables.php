<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
 */
return new class extends Migration
{
    private const TABLES = ['organizations', 'projects'];

    private const CHUNK_SIZE = 500;

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->char('public_id', 26)->nullable()->after('id');
            });

            $this->backfill($table);

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->char('public_id', 26)->nullable(false)->change();
                $blueprint->unique('public_id', $table.'_public_id_unique');
            });
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
        DB::table($table)->select('id')->orderBy('id')->chunkById(self::CHUNK_SIZE, function ($rows) use ($table): void {
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->update([
                    'public_id' => (string) Str::ulid(),
                ]);
            }
        });
    }
};
