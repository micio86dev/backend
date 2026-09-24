<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `organizations.allowed_domains` — BEAI Public API (`/v1`) step 4.
 *
 * SPEC.md §3.3 "`GET /v1/organization` ... `allowed_domains`" and §3.3's
 * `createInterview` request notes: "its host must be in the organization's
 * allowed domains (configured in backoffice, same list used for
 * `frame-ancestors`)". Nullable `jsonb` array of hostnames — `null` means
 * "none configured", rendered as `[]` in the public response
 * (`App\PublicApi\Serializers\OrganizationSerializer`), never `null`
 * itself, mirroring `Project::webhook_events`'s own null-vs-empty-array
 * discipline elsewhere in this codebase.
 *
 * Deliberately NOT accepted by `UpdateOrganizationRequest`/
 * `OrganizationController::update()` yet — step 11 adds the backoffice
 * editor for it (see the one-line note added there in this same commit).
 * The column exists now because the public API needs to READ it starting
 * this step; a write surface is a separate concern with its own UX (likely
 * a structured list editor, not a bare PATCH array).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->jsonb('allowed_domains')->nullable()->after('public_api_rate_limit_test');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('allowed_domains');
        });
    }
};
