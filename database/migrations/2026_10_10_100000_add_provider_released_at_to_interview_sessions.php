<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `interview_sessions.provider_released_at` (tavus-single-session-interview, API-04).
 *
 * The moment a release of this row's provider conversation was attempted, written on every row
 * of the organization that shares the ref. A continuation must never be granted on a conversation
 * the deferred release job (or any other release) already ended, and nothing else on the row says
 * so: `/end` keeps the ref. Nullable, no default, no backfill (a row released before this column
 * existed is simply unmarked, which only means a late continuation is attempted as it was
 * before). Not personal data; never serialised by any resource. Dropped by `down()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interview_sessions', function (Blueprint $table): void {
            $table->timestampTz('provider_released_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('interview_sessions', function (Blueprint $table): void {
            $table->dropColumn('provider_released_at');
        });
    }
};
