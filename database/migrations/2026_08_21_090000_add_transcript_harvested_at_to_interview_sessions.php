<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that a resumed session's earlier transcript has already been stored.
 *
 * `replaceUtterances()` at /end is authoritative for ONE provider session, and
 * it DELETEs before inserting. A resumed competency spans several sessions, so
 * that delete was erasing turns belonging to the ones that came before — every
 * word the candidate said before resuming.
 *
 * This flag is what lets /end tell the two cases apart: null means the stored
 * rows came only from the session it is about to fetch, so replacing them is
 * correct; non-null means an earlier session's turns are in there too and must
 * be appended to, not replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interview_sessions', function (Blueprint $table): void {
            $table->timestampTz('transcript_harvested_at')->nullable()->after('ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('interview_sessions', function (Blueprint $table): void {
            $table->dropColumn('transcript_harvested_at');
        });
    }
};
