<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `interview_sessions.error_count` — the durable bound on automatic re-offers
 * (interview-continuous-flow, D1).
 *
 * A competency abandoned to `error` is offered to the candidate once more. The
 * bound has to survive the reset that re-offers it: `ResetSessionForRetry` clears
 * `status`, `provider_session_ref`, `ended_reason` and `ended_at`, and deliberately
 * does NOT touch this column. A counter that its own reset clears is not a bound —
 * it grants unlimited retries.
 *
 * BACKFILL — required, not cosmetic. Every row already sitting at `status = 'error'`
 * has spent one attempt. Leaving them at the 0 default would grant them three
 * attempts against a maximum of two.
 *
 * DO NOT RUN down() ON A CODE REVERT.
 * The column is additive and unread by reverted code, so leaving it costs nothing.
 * Dropping it while a re-offered session sits at `pending` erases the only record
 * that its one re-offer was already spent; on re-deploy that row reads 0 and is
 * granted a second one. `down()` exists for local development, where the table is
 * disposable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interview_sessions', function (Blueprint $table): void {
            // unsignedTinyInteger: the bound is 2. A wider type would invite the
            // reading that this is a general-purpose attempt log, which it is not.
            $table->unsignedTinyInteger('error_count')
                ->default(0)
                ->after('ended_reason');
        });

        // Not scoped by organization on purpose: the invariant is per row, and every
        // errored row in every tenant has spent exactly one attempt by definition.
        DB::table('interview_sessions')
            ->where('status', 'error')
            ->update(['error_count' => 1]);
    }

    public function down(): void
    {
        // See the class docblock: this is for local development only. Running it
        // against an environment with live re-offered sessions silently resets the
        // bound on every one of them.
        Schema::table('interview_sessions', function (Blueprint $table): void {
            $table->dropColumn('error_count');
        });
    }
};
