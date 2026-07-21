<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create `utterances` table (C7a — Interview Session Mechanics).
 *
 * Stores live transcript lines for an interview session.
 * HeyGen: replaced by authoritative server transcript at /end.
 * Tavus: best-effort live rows; not replaced at /end.
 *
 * Design invariants:
 * - interview_session_id: cascadeOnDelete.
 * - speaker enum {candidate, avatar}.
 * - ts: timestampTz (sent by client for ordering; server-received time also available via created_at).
 * - Composite index (organization_id, interview_session_id) per D22 org-lead rule.
 *
 * REQ: Utterance tenant model (C7a)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utterances', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('interview_session_id')
                ->constrained('interview_sessions')
                ->cascadeOnDelete();

            // Redundant org_id for composite index efficiency (no join needed on filter).
            $table->unsignedBigInteger('organization_id');

            // Speaker: 'candidate' or 'avatar'.
            $table->string('speaker');

            $table->text('text');

            // Client-side timestamp of utterance (timestampTz).
            $table->timestampTz('ts');

            // D22 org-lead composite index.
            $table->index(['organization_id', 'interview_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utterances');
    }
};
