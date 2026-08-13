<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add org-level webhook default columns to `organizations` (backoffice-missing-pages, D1).
 *
 * `default_webhook_url` / `default_webhook_secret` / `default_webhook_events` mirror
 * `projects`'s column types verbatim (`create_projects_table.php:44-45`,
 * `add_webhook_events_to_projects_table.php:31`) so a copy at project-creation time is a
 * straight assignment, not a conversion.
 *
 * All three are NULLABLE WITH NO DEFAULT — unlike `projects.webhook_events` (which
 * defaults to both event types), absence here means "no org default configured", a
 * real and distinct state from an empty array. `default_webhook_secret` is encrypted
 * at rest via the model's `encrypted` cast (App\Models\Organization) and hidden from
 * serialization, matching `Project::$hidden`/`$casts` for `webhook_secret`.
 *
 * `organizations` itself carries no `organization_id` — its own `id` IS the tenant
 * key, so this is a plain additive migration on an existing table, not a new
 * tenant-scoped table (D1).
 *
 * REQ: organizations.default_webhook_* (backoffice-missing-pages D1/D3)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('default_webhook_url', 2048)->nullable();
            $table->text('default_webhook_secret')->nullable(); // encrypted at rest via model cast
            $table->jsonb('default_webhook_events')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['default_webhook_url', 'default_webhook_secret', 'default_webhook_events']);
        });
    }
};
