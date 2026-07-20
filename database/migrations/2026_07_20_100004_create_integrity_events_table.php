<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create `integrity_events` table (C7a — Interview Session Mechanics).
 *
 * Stores proctoring events detected by the client during an interview session.
 * 13 canonical event kinds from proctor-config.ts; validated at ingestion.
 *
 * Design invariants:
 * - interview_session_id: cascadeOnDelete.
 * - kind: varchar (validated against 13 canonical kinds at application layer).
 * - payload: jsonb for flexible event metadata.
 * - ts: timestampTz (client-side event occurrence time).
 * - Composite index (organization_id, interview_session_id) per D22 org-lead rule.
 *
 * REQ: IntegrityEvent tenant model (C7a)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrity_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('interview_session_id')
                ->constrained('interview_sessions')
                ->cascadeOnDelete();

            // Redundant org_id for composite index efficiency.
            $table->unsignedBigInteger('organization_id');

            // Event kind — validated against 13 canonical proctoring event types.
            $table->string('kind');

            // Flexible metadata (face coords, device info, etc.) stored as JSONB.
            $table->jsonb('payload');

            // Client-side event timestamp.
            $table->timestampTz('ts');

            // D22 org-lead composite index.
            $table->index(['organization_id', 'interview_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrity_events');
    }
};
