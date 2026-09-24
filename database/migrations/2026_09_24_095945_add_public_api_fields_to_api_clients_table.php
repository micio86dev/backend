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
 * Postgres enum type to extend later, and the application's own
 * `ApiKeyGenerator` mode union is the real source of truth either way.
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
