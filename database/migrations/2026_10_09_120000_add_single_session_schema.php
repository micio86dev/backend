<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema for the single-session Tavus interview
 * (tavus-single-session-interview, API-01, design N2/D9).
 *
 * - `interview_sessions.conversation_plan`: nullable JSON, written once on the
 *   row whose `issue()` created a multi-competency conversation. Holds codes,
 *   primary questions, budgets and a char count; never anchors, indicators or
 *   prompt text. Nullable, no default, no backfill; not personal data and
 *   never serialised by any resource.
 * - `interview_session_live_periods_one_open_per_ref`: partial unique index, at
 *   most one OPEN period per non-null provider ref. Several competency rows may
 *   share one provider conversation, but only one may be live on it at a time.
 *   Today every ref is unique to one row, so no existing data can violate it.
 *
 * Both are additive and dropped by `down()`.
 */
return new class extends Migration
{
    private const INDEX = 'interview_session_live_periods_one_open_per_ref';

    public function up(): void
    {
        Schema::table('interview_sessions', function (Blueprint $table): void {
            $table->json('conversation_plan')->nullable();
        });

        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX.'
             ON interview_session_live_periods (provider_session_ref)
             WHERE provider_session_ref IS NOT NULL AND ended_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

        Schema::table('interview_sessions', function (Blueprint $table): void {
            $table->dropColumn('conversation_plan');
        });
    }
};
