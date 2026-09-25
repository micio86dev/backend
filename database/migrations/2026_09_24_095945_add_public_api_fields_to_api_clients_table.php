<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public API fields on `api_clients` (public-api step 2 — SPEC.md §3.1, §8 Q1).
 *
 * `key_prefix` — the VISIBLE identifier: `beai_live_`/`beai_test_` followed by
 * the first 8 chars of the random part. Nullable: every row created before
 * this migration has a raw key that is, by design, unrecoverable (only its
 * hash was ever stored) — `App\Support\PublicApi\ApiKeyResolver` falls back
 * to the legacy hash-only lookup for those. Indexed — it is the hot-path
 * lookup column for the new `/v1` middleware, the same reason `key_hash`
 * carries its own unique index.
 *
 * `mode` — `live`|`test` (SPEC.md §3.7 "Test mode"). NOT NULL, defaulting
 * every pre-migration row to `live`: they were all issued before test mode
 * existed, so `live` is the true value, not a guess. The CHECK constraint
 * mirrors the `avatar_templates.provider` precedent (D8) — cheaper than a
 * Postgres enum type to extend later.
 *
 * Review follow-up (public-api step 3, Part A finding 1): the default and
 * the CHECK constraint below are LITERAL `'live'`/`'test'`, not derived from
 * `App\Enums\ApiKeyMode::cases()` as the first version of this migration
 * did. A migration is a FROZEN snapshot of the schema at the moment it ran —
 * once applied anywhere, its `up()` must never change meaning again, even if
 * the enum it once referenced later gains a case. Deriving from the live
 * enum silently changes what an already-applied migration WOULD have done
 * if re-run, which is exactly backwards for a migration. `App\Enums\
 * ApiKeyMode::cases()` remains the single source of truth for every piece of
 * code that runs AFTER this migration (the model cast, the resolver, the
 * generator); this file is the one place that must keep repeating the two
 * literals it captured. `tests/Unit/C5/ApiKeyModeTest.php` asserts
 * `ApiKeyMode::cases()` values equal `['live', 'test']` precisely so a THIRD
 * case being added is caught immediately, as a prompt to write a NEW
 * migration extending the constraint — never to edit this one in place.
 * Safe to edit in place today only in the narrow sense that this migration
 * has not been applied in any shared environment yet; once it has, this file
 * is frozen exactly like every other migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_clients', function (Blueprint $table): void {
            $table->string('key_prefix', 20)->nullable()->after('key_hash');
            $table->string('mode', 4)->default('live')->after('key_prefix');

            $table->index('key_prefix');
        });

        DB::statement(
            "ALTER TABLE api_clients ADD CONSTRAINT api_clients_mode_check
             CHECK (mode IN ('live', 'test'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE api_clients DROP CONSTRAINT IF EXISTS api_clients_mode_check');

        Schema::table('api_clients', function (Blueprint $table): void {
            $table->dropIndex(['key_prefix']);
            $table->dropColumn(['key_prefix', 'mode']);
        });
    }
};
