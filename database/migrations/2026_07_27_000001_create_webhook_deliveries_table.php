<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create `webhook_deliveries` table (C10 — Webhooks Integration).
 *
 * Tenant-scoped delivery audit trail: one row per (org, project, event_type, dedupe_key).
 * Written BEFORE the outbound HTTP call by WebhookDeliveryRecorder; the row — never the
 * queue — is the single source of truth for attempt accounting, idempotency, and the C11
 * read model. `payload` is frozen at INSERT time and never regenerated.
 *
 * Design invariants (design.md D1, D2, D22):
 * - organization_id: cascadeOnDelete, D22 lead column on every composite index.
 * - project_id: restrictOnDelete (mirrors interview_sessions FK policy; projects soft-delete).
 * - participant_id: cascadeOnDelete (both event types are participant-scoped).
 * - delivery_id: the receiver-facing X-BEAI-Delivery-Id, generated ONCE, immutable, UNIQUE.
 * - dedupe_key: string(191) NOT NULL — Postgres treats NULLs as distinct, so a nullable
 *   column would silently disable the unique dedupe arbitration.
 * - payload/payload_version: frozen at creation; never regenerated on re-score.
 * - attempt_count: mirrored from $job->attempts() (assignment, never increment).
 * - max_attempts: frozen from config at creation so a later config edit cannot
 *   retroactively change a live delivery's contract.
 * - No `delivering` in-flight state (design D2): a crashed worker with no reaper would
 *   strand rows there forever; attempt_count + last_attempt_at carry the same information.
 *
 * CHECK constraints below use `DB::statement()` raw DDL after `Schema::create()` — the same
 * technique (not the same constraint kind) as the partial unique index at
 * `2026_07_17_200001_create_projects_table.php:63-67`; raw DDL in a migration is an
 * accepted precedent (design.md S20). They make illegal states unrepresentable:
 *   - status='skipped' <=> skip_reason IS NOT NULL
 *   - status='delivered' <=> delivered_at IS NOT NULL
 *   - status='skipped' => attempt_count = 0
 *   - attempt_count <= max_attempts
 *
 * REQ: webhook_deliveries schema (C10 D1)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->restrictOnDelete();

            $table->foreignId('participant_id')
                ->constrained('participants')
                ->cascadeOnDelete();

            // Receiver-facing X-BEAI-Delivery-Id — generated ONCE at row creation, immutable.
            $table->uuid('delivery_id')->unique();

            // 'progress' | 'evaluation' — cast to WebhookEventType at the model layer.
            $table->string('event_type');

            // Semantic dedupe key: NOT NULL is mandatory (see class doc above).
            $table->string('dedupe_key', 191);

            // 'pending' | 'delivered' | 'failed_permanent' | 'dead' | 'skipped'.
            $table->string('status');

            // 'no_webhook_url' | 'no_webhook_secret' | 'event_type_disabled'; null unless skipped.
            $table->string('skip_reason')->nullable();

            // Frozen copy of projects.webhook_url at creation; null only on skipped/no_webhook_url.
            $table->string('target_url', 2048)->nullable();

            // Frozen payload — never regenerated after a later re-score.
            $table->jsonb('payload');

            $table->string('payload_version', 16);

            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('max_attempts');

            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();

            $table->unsignedSmallInteger('last_response_status')->nullable();

            // Truncated to config('webhooks.errors.max_last_error_chars') AFTER secret redaction.
            $table->text('last_error')->nullable();

            $table->timestamps();

            // The recorder's INSERT arbiter — collapses duplicate emissions (e.g. the SSO
            // TOCTOU race) into one row. D22 org-first; also usable as a prefix index for
            // (org), (org, project), (org, project, event_type) scans.
            $table->unique(
                ['organization_id', 'project_id', 'event_type', 'dedupe_key'],
                'webhook_deliveries_org_project_event_dedupe_unique'
            );

            // C11 dashboards (status='dead') and a future sweeper (pending + due). D22 org-first.
            $table->index(['organization_id', 'status', 'next_attempt_at']);

            // C11 "all webhooks for this candidate" + cross-tenant isolation tests. D22 org-first.
            $table->index(['organization_id', 'participant_id']);
        });

        // Raw-DDL CHECK constraints — make illegal states unrepresentable (S20 precedent).
        DB::statement(
            'ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_skip_reason_check
             CHECK ((status = \'skipped\') = (skip_reason IS NOT NULL))'
        );

        DB::statement(
            'ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_delivered_at_check
             CHECK ((status = \'delivered\') = (delivered_at IS NOT NULL))'
        );

        DB::statement(
            'ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_skipped_attempt_count_check
             CHECK (status <> \'skipped\' OR attempt_count = 0)'
        );

        DB::statement(
            'ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_attempt_count_max_check
             CHECK (attempt_count <= max_attempts)'
        );
    }

    public function down(): void
    {
        // CHECK constraints and indexes are dropped automatically with the table.
        Schema::dropIfExists('webhook_deliveries');
    }
};
