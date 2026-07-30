<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * notification_logs (C12 — Notifications & Reminders, D3).
 *
 * This table is the arbiter of "already handled", of "suppressed", and of "we
 * tried and failed". Not the queue, not application logic — the row.
 *
 * It is also, by construction, the read model for a future operator feed:
 * org-scoped, typed and status-bearing from day one, which is exactly why C12
 * never needs Laravel's `database` notification channel (polymorphic,
 * untenanted, no unique index, and framework-namespaced so the tenancy arch
 * test could never see it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table): void {
            $table->id();

            // All four dedupe columns are NOT NULL, and that is load-bearing:
            // Postgres treats NULLs as DISTINCT in a unique index, so a single
            // nullable column here would silently disable the arbiter entirely.
            // That defect class was already found and fixed once, on
            // webhook_deliveries.
            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('notification_type', 64);
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');

            $table->string('status', 16)->default('pending');
            $table->string('suppression_reason', 32)->nullable();

            $table->unsignedInteger('recipient_count')->default(0);

            // How many `suppressed` rows for this (org, type) accumulated since
            // the last `sent` one. Carried into the next email that actually
            // goes out, so a suppression window never silently swallows scale.
            $table->unsignedInteger('suppressed_carried_count')->default(0);

            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            // THE arbiter. A duplicate INSERT raises 23505, which the recorder
            // catches and turns into a decision based on the existing row's
            // status — rather than a read-then-write that races.
            $table->unique(
                ['organization_id', 'notification_type', 'subject_type', 'subject_id'],
                'notification_logs_org_type_subject_unique'
            );

            // The suppression-window query: newest `sent` row for (org, type).
            // Organization first, per the composite-index rule.
            $table->index(['organization_id', 'notification_type', 'status', 'sent_at']);
        });

        // Raw-DDL CHECK constraints — make illegal states unrepresentable.
        DB::statement(
            "ALTER TABLE notification_logs ADD CONSTRAINT notification_logs_suppression_reason_check
             CHECK ((status = 'suppressed') = (suppression_reason IS NOT NULL))"
        );

        DB::statement(
            "ALTER TABLE notification_logs ADD CONSTRAINT notification_logs_sent_at_check
             CHECK ((status = 'sent') = (sent_at IS NOT NULL))"
        );
    }

    public function down(): void
    {
        // CHECK constraints and indexes drop with the table.
        Schema::dropIfExists('notification_logs');
    }
};
