<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * audit_logs — append-only record of admin/operator mutations (C13).
 *
 * A binding NFR (CLAUDE.md, BEAI_BRIEF) with no implementation until now.
 *
 * Shaped after `ai_requests`: no `updated_at`, no updates from business logic,
 * an arch guard rather than a convention. An audit trail that can be edited by
 * the system it audits is not evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Nullable on purpose: an M2M client or a console command has no
            // User behind it. "Something changed and no human did it" is
            // exactly the event an auditor most wants to see — recording it
            // anonymously beats not recording it.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action', 64);
            $table->string('subject_type', 64);

            // No FK: an audit trail must outlive its subject. The same
            // reasoning as notification_logs.subject_id.
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // The dashboard reads "what happened in this org lately", and the
            // subject index answers "what happened to this record".
            // Organization first, per the composite-index rule.
            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
