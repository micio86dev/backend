<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add scheduling to `participants` (interview-scheduling, PR-A, design AD-1).
 *
 * `scheduled_at` — timestampTz, nullable. `null` = never scheduled (today's
 * immediate path). Matches the existing convention on this table:
 * `started_at`/`completed_at` are already `timestampTz`
 * (`2026_07_20_000001_create_participants_table.php`).
 *
 * `scheduling_status` — string(16), nullable, cast to the backed
 * `App\Enums\ParticipantSchedulingStatus` enum (pending, notice_sent, started,
 * cancelled). `null` exactly when `scheduled_at` is `null` — there is
 * deliberately no "none"/unscheduled CASE in the enum itself; the pair of
 * nullable columns carries that meaning, enforced by the CHECK constraint
 * below rather than trusted to application code.
 *
 * The CHECK constraint mirrors the exact style already used on
 * `notification_logs` (`2026_07_30_000001_create_notification_logs_table.php`):
 * `(scheduled_at IS NULL) = (scheduling_status IS NULL)` — a status with no
 * time, or a time with no status, is an illegal state made unrepresentable.
 *
 * The composite index `(scheduling_status, scheduled_at)` is the literal
 * predicate shape of the future sweep's due-participant queries
 * (`WHERE scheduling_status = 'pending' AND scheduled_at <= ?` and the
 * `notice_sent` equivalent) — additional to, not a replacement for, the
 * existing `(organization_id, status)` / `(organization_id, project_id)`
 * indexes on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table): void {
            $table->timestampTz('scheduled_at')->nullable()->after('completed_at');
            $table->string('scheduling_status', 16)->nullable()->after('scheduled_at');

            $table->index(['scheduling_status', 'scheduled_at'], 'participants_scheduling_status_scheduled_at_index');
        });

        DB::statement(
            'ALTER TABLE participants ADD CONSTRAINT participants_scheduling_status_pair_check
             CHECK ((scheduled_at IS NULL) = (scheduling_status IS NULL))'
        );
    }

    public function down(): void
    {
        // CHECK constraint and index drop with the columns.
        Schema::table('participants', function (Blueprint $table): void {
            $table->dropColumn(['scheduled_at', 'scheduling_status']);
        });
    }
};
