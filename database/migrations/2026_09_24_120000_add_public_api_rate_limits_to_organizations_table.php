<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organization Public API (`/v1`) rate-limit overrides (public-api step
 * 3 — SPEC.md §3.2 "Rate limiting: per organization, token bucket. Defaults
 * `live` 600 req/min, `test` 120 req/min (configurable per org in
 * backoffice)").
 *
 * Both NULLABLE, with NO stored default — `null` means "use the platform
 * default", read from `config('public_api.rate_limit.live'|'test')`
 * (`App\Http\Middleware\PublicApi\RateLimitPublicApi::maxAttemptsFor()`) at
 * request time, not baked into the row. This mirrors `organizations.
 * logo_path`/`primary_color` (ruling 9): a column that only ever overrides a
 * platform default must stay null-by-default forever, never acquire a
 * migration-time literal that then silently disagrees with a config default
 * changed later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->unsignedInteger('public_api_rate_limit_live')->nullable()->after('primary_color');
            $table->unsignedInteger('public_api_rate_limit_test')->nullable()->after('public_api_rate_limit_live');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['public_api_rate_limit_live', 'public_api_rate_limit_test']);
        });
    }
};
