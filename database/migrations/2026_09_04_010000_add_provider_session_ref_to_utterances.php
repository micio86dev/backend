<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHICH provider session each utterance belongs to.
 *
 * A resumed competency spans several provider sessions, and a provider answers
 * for the current `provider_session_ref` only. The DELETE that makes
 * fetch-then-store authoritative therefore has to be bounded to the stretch
 * being replaced — delete everything and the earlier stretches are destroyed,
 * delete nothing and the live `/utterance` rows are duplicated by the
 * provider's copy of the same turns.
 *
 * An auto-increment watermark was tried first and CANNOT express this: the
 * provider's rows are always inserted after the live rows they replace, so any
 * id high enough to protect the former also buries the latter. Ownership is not
 * a position in a sequence; it is a fact about the row, and it belongs on the row.
 *
 * INDEX BUILD IS NOT CONCURRENT, deliberately. A plain CREATE INDEX takes a
 * SHARE lock and blocks writes for the duration, which on this table would be
 * candidate-visible: `/utterance` runs once per conversational turn of every
 * live interview. The build is sub-second at the current row count, so the
 * simple form is chosen knowingly. Revisit before the table is large enough for
 * the lock to outlast a request: CREATE INDEX CONCURRENTLY cannot run inside a
 * transaction and needs `public $withinTransaction = false` plus a raw statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utterances', function (Blueprint $table): void {
            $table->string('provider_session_ref')->nullable();

            // Org-lead, matching the D22 index this table already carries and
            // the binding constraint that composite indexes lead with
            // organization_id. It covers replaceUtteranceStretch()'s DELETE
            // exactly, which filters on all three columns.
            // Named explicitly. The generated name would be 74 bytes, past
            // PostgreSQL's 63-byte identifier limit, so the server would truncate
            // it with a NOTICE and the rollback below would be relying on that
            // truncation matching — and any future index truncating to the same
            // 63 bytes would collide.
            $table->index(
                ['organization_id', 'interview_session_id', 'provider_session_ref'],
                'utterances_org_session_ref_index',
            );

            // D22's index is a proper PREFIX of the one above, so Postgres can
            // serve everything it served from the new one. Keeping both would
            // maintain two B-trees on every insert into the highest-volume table
            // in the product for no query either cannot answer.
            $table->dropIndex(['organization_id', 'interview_session_id']);
        });

        // BACKFILL THE IN-FLIGHT SESSIONS. Leaving every existing row NULL is
        // right for a session that has already ended — nothing can now say which
        // stretch its turns came from, so they are preserved rather than guessed
        // at, and no later fetch will claim them.
        //
        // It is WRONG for a session still `in_corso`, and this migration deploys
        // into those. Its live rows would keep a NULL ref while /end deletes by
        // the session's current ref, matching only rows written after the deploy
        // — and then inserts the provider transcript, which covers the earlier
        // turns too. Every turn spoken before the deploy would be stored twice,
        // which is exactly the duplication this column exists to prevent.
        //
        // An open session has one stretch that can be named, and it is the
        // current ref, so those rows CAN be attributed.
        DB::statement(<<<'SQL'
            UPDATE utterances u
               SET provider_session_ref = s.provider_session_ref
              FROM interview_sessions s
             WHERE s.id = u.interview_session_id
               AND u.provider_session_ref IS NULL
               AND s.status = 'in_corso'
               AND s.provider_session_ref IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('utterances', function (Blueprint $table): void {
            $table->index(['organization_id', 'interview_session_id']);
            $table->dropIndex('utterances_org_session_ref_index');
            $table->dropColumn('provider_session_ref');
        });
    }
};
