<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguishes a primary-question avatar turn from a follow-up turn in the
 * transcript (framework-catalogue-authoring PR7, design D8).
 *
 * Nullable, values `primary` | `follow_up`, set only on `speaker = 'avatar'`
 * rows. Every row that exists at migration time was written before
 * `TurnClassifier` existed and carries no classification — NULL is that
 * honest absence, never backfilled to a guess. A CHECK constraint pins the
 * closed set (`framework_catalog_revisions.state`'s own pattern, same PR
 * sequence): `TurnClassifier`'s audit counts rows by exact string equality
 * against `'primary'`, so a typo or a third value written outside the
 * classifier would silently undercount without one. NULL always satisfies a
 * CHECK, so a `candidate`-speaker row (never classified) needs no
 * exemption clause.
 *
 * Z15 (R4-turn-kind-check-lock, framework-catalogue-authoring, REQUIRED
 * BEFORE ARCHIVE): a bare `ADD CONSTRAINT ... CHECK (...)` takes an ACCESS
 * EXCLUSIVE lock on `utterances` for the DURATION of validating every
 * existing row against it — blocking every concurrent read AND write on
 * the highest-volume table in the interview path for however long that
 * scan takes. Split into `NOT VALID` (adds the constraint definition under
 * a brief lock, skipping the scan) followed by a separate
 * `VALIDATE CONSTRAINT` (does the scan under a SHARE UPDATE EXCLUSIVE lock,
 * which still permits concurrent reads and writes) — the standard Postgres
 * zero-downtime pattern for adding a CHECK to an existing table.
 *
 * `public $withinTransaction = false` IS THE FIX, not the split alone (gga
 * review finding, blocking): Laravel wraps every migration in ONE
 * transaction by default, and Postgres DDL is transactional — inside a
 * single transaction, `ADD COLUMN` already takes the ACCESS EXCLUSIVE lock,
 * and it stays held for the `VALIDATE CONSTRAINT` scan too, since nothing
 * commits until the whole migration does. The split only has its intended
 * effect when each statement is allowed to commit on its own. This repo
 * already names this exact trap:
 * `2026_09_04_010000_add_provider_session_ref_to_utterances.php`'s own
 * docblock. Running outside a transaction risks a partial migration on
 * failure, accepted here knowingly: every existing row is NULL, so
 * `VALIDATE CONSTRAINT` cannot fail.
 */
return new class extends Migration
{
    /**
     * @var bool
     */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('utterances', function (Blueprint $table): void {
            $table->string('turn_kind')->nullable();
        });

        DB::statement(
            "ALTER TABLE utterances
             ADD CONSTRAINT utterances_turn_kind_check CHECK (turn_kind IN ('primary', 'follow_up')) NOT VALID"
        );

        DB::statement('ALTER TABLE utterances VALIDATE CONSTRAINT utterances_turn_kind_check');
    }

    public function down(): void
    {
        Schema::table('utterances', function (Blueprint $table): void {
            $table->dropColumn('turn_kind');
        });
    }
};
