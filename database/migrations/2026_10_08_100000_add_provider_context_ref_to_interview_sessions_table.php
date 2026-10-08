<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider context handle on `interview_sessions` (heygen-context-cleanup):
 * `provider_context_ref` is the id of the `/v1/contexts` entry HeyGen's `issue()`
 * creates for every session. Nothing ever deleted it, so the account accumulated
 * one `beai-*` context per session; persisting the id next to
 * `provider_session_ref` is what lets `teardown()` delete it after the stop.
 *
 * Declared exactly like `provider_session_ref`: a nullable string, no index
 * (it is only ever read through the session row itself, never looked up by
 * value), no default and no backfill. Contexts of sessions that predate this
 * column are not recoverable from our data and stay NULL. Adding a nullable
 * column with no default is metadata-only in Postgres (no table rewrite), and
 * the previous release tolerates the extra column, so the deploy window is safe
 * in either order.
 *
 * Tavus and the mock provider have no context concept and keep NULL.
 *
 * Both directions are guarded so a rerun after a partial failure is safe.
 */
return new class extends Migration
{
    private const COLUMN = 'provider_context_ref';

    public function up(): void
    {
        if (Schema::hasColumn('interview_sessions', self::COLUMN)) {
            return;
        }

        Schema::table('interview_sessions', function (Blueprint $table): void {
            $table->string(self::COLUMN)->nullable()->after('provider_session_ref');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('interview_sessions', self::COLUMN)) {
            return;
        }

        Schema::table('interview_sessions', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }
};
