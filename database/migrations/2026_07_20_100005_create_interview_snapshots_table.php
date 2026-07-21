<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create `interview_snapshots` table (C7a — Interview Session Mechanics).
 *
 * Stores S3 references for JPEG proctoring snapshots taken during an interview session.
 * Images are validated (JPEG magic bytes) and stored to S3 by the application layer;
 * only the S3 key is stored here. No TTL in C7a (deferred to C13/GDPR).
 *
 * Design invariants:
 * - interview_session_id: cascadeOnDelete.
 * - s3_key: server-generated path ({org_id}/{participant_id}/{session_id}/{uuid}.jpg).
 * - taken_at: server-set timestampTz (NOT client-supplied; prevents time forgery).
 * - Composite index (organization_id, interview_session_id) per D22 org-lead rule.
 *
 * REQ: InterviewSnapshot tenant model (C7a)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_snapshots', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('interview_session_id')
                ->constrained('interview_sessions')
                ->cascadeOnDelete();

            // Redundant org_id for composite index efficiency.
            $table->unsignedBigInteger('organization_id');

            // Server-generated S3 key: {org_id}/{participant_id}/{session_id}/{uuid}.jpg
            $table->string('s3_key');

            // Server-set timestamp — NOT client-supplied.
            $table->timestampTz('taken_at');

            // D22 org-lead composite index.
            $table->index(['organization_id', 'interview_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_snapshots');
    }
};
