<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `exports` — the async bulk-export jobs `POST/GET /v1/exports` and
 * `GET /v1/exports/{id}` read and write (public-api step 8, SPEC.md §3.3
 * "Exports", `openapi.yaml`'s `Export`/`CreateExportRequest` schemas).
 *
 * Tenant-scoped (`App\Models\Export extends TenantModel`, same discipline as
 * `webhook_deliveries` — see that migration's own docblock): `organization_id`
 * is stamped by `TenantScoped::creating` and must never be `$fillable`.
 *
 * `public_id` (`char(26)`, `exp_` prefix on the way out via
 * `App\Support\PublicApi\PublicId`) is set at table-creation time — this
 * table has no pre-`public_id` history to backfill, unlike
 * `2026_09_24_130000_add_public_id_to_organizations_and_projects_tables.php`,
 * so the column is simply `NOT NULL UNIQUE` from the start (mirrors
 * `2026_09_24_140100_create_interview_events_table.php`'s identical choice
 * for the same reason).
 *
 * `mode` (`live`/`test`, `App\Enums\ApiKeyMode`) is frozen from the
 * requesting key at creation — SPEC.md §3.7 "a `beai_test_` key's requests
 * must never read or write live data" — an export always reports and reads
 * back the SAME mode it was created under, never the CURRENT request's mode
 * (which could differ if a live and a test key are both used against the
 * same organization over the export's lifetime).
 *
 * **One active export per organization at a time** (SPEC.md §3.3
 * `POST /exports`, `429 export_in_progress`) is enforced by a PARTIAL UNIQUE
 * INDEX, not merely a SELECT-then-INSERT check in the controller — the same
 * "make illegal states unrepresentable at the database level" discipline
 * `2026_07_17_200001_create_projects_table.php`'s own slug-uniqueness index
 * and `webhook_deliveries`' CHECK constraints already apply (S20 precedent).
 * A SELECT-then-INSERT race (two concurrent `POST /exports` both observing
 * zero in-flight rows) is closed by the same mechanism Postgres already
 * gives every other unique index: the SECOND concurrent INSERT into this
 * index raises `23505`, which the controller catches and answers
 * `429 export_in_progress` for, exactly like `WebhookDeliveryController::
 * redeliver()`'s own conditional-UPDATE race is closed by ordinary row
 * locking rather than an apparent-but-not-actual atomic PHP-level check.
 *
 * `status` lifecycle (`queued` -> `processing` -> `ready`|`failed`; `ready`
 * may later age into `expired` — the DOWNLOAD ARCHIVE's own window, never an
 * interview's — `openapi.yaml`'s own `Export.status` description): only
 * `queued`/`processing` participate in the partial unique index above, so a
 * `ready`/`failed`/`expired` row never blocks a new export.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table): void {
            $table->id();

            $table->char('public_id', 26);

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            // 'live' | 'test' — App\Enums\ApiKeyMode, frozen from the
            // requesting key at creation (see class doc).
            $table->string('mode', 4);

            // 'all' | 'interviews'.
            $table->string('scope', 16);

            // 'jsonl' | 'csv'.
            $table->string('format', 8);

            $table->timestampTz('from_at')->nullable();
            $table->timestampTz('to_at')->nullable();

            $table->boolean('include_transcripts')->default(true);
            $table->boolean('include_scoring')->default(true);
            $table->boolean('include_audio')->default(false);

            // 'queued' | 'processing' | 'ready' | 'failed' | 'expired'.
            $table->string('status')->default('queued');

            $table->unsignedInteger('record_count')->nullable();
            $table->string('object_key', 512)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestampsTz();
            $table->timestampTz('completed_at')->nullable();

            $table->unique('public_id', 'exports_public_id_unique');

            // The list/detail read path — newest first, per organization.
            // D22 org-lead rule.
            $table->index(['organization_id', 'created_at', 'id'], 'exports_organization_ordering_index');
        });

        // Partial unique index — "at most one queued/processing export per
        // organization" at the database level, closing the concurrent-POST
        // race a PHP-level SELECT-then-INSERT check alone cannot (see class
        // doc above).
        DB::statement(
            "CREATE UNIQUE INDEX exports_one_active_per_organization
             ON exports (organization_id)
             WHERE status IN ('queued', 'processing')"
        );

        // Raw-DDL CHECK constraints — make illegal states unrepresentable
        // (same S20 precedent this class doc already cites for the partial
        // unique index above; technique mirrors
        // `2026_09_24_095945_add_public_api_fields_to_api_clients_table.php`'s
        // identical `mode IN (...)` constraint).
        DB::statement(
            "ALTER TABLE exports ADD CONSTRAINT exports_mode_check
             CHECK (mode IN ('live', 'test'))"
        );
        DB::statement(
            "ALTER TABLE exports ADD CONSTRAINT exports_scope_check
             CHECK (scope IN ('all', 'interviews'))"
        );
        DB::statement(
            "ALTER TABLE exports ADD CONSTRAINT exports_format_check
             CHECK (format IN ('jsonl', 'csv'))"
        );
        DB::statement(
            "ALTER TABLE exports ADD CONSTRAINT exports_status_check
             CHECK (status IN ('queued', 'processing', 'ready', 'failed', 'expired'))"
        );
    }

    public function down(): void
    {
        // CHECK constraints and the partial unique index are dropped
        // automatically with the table.
        Schema::dropIfExists('exports');
    }
};
