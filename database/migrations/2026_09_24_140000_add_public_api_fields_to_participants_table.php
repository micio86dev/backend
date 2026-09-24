<?php

declare(strict_types=1);

use App\Support\Database\Migrations\Concerns\ChecksColumnNullability;
use App\Support\Database\Migrations\Concerns\RepairsInvalidUniqueIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * BEAI Public API (`/v1`) fields on `participants` — public-api step 5,
 * SPEC.md §3.3 "Step 5 adds to `participants` the columns the public
 * resource needs".
 *
 * `public_id` (`int_…`, G-05): same char(26)-bare-ulid shape as the step 4
 * migration on `organizations`/`projects` — see that migration's own
 * docblock for why it is nullable-then-backfilled-then-NOT-NULL, why this
 * migration disables the wrapping transaction the same way (Part A item 1's
 * fix, applied here from the start rather than needing its own follow-up),
 * and why every schema-changing statement below is individually guarded for
 * TRUE idempotency on a rerun (gga round 4 finding 3) — the identical
 * reasoning applies here, extended to every column this migration adds, not
 * just `public_id`.
 *
 * `metadata` — client-owned free-form key/value pairs
 * (`App\Rules\PublicApi\Metadata`: ≤ 20 keys, key ≤ 40 chars, value ≤ 500
 * chars), stored as `jsonb`. Nullable; a participant created before this
 * column existed, or one created directly in the backoffice, has none.
 *
 * `exit_redirect_url` — an OPTIONAL per-enrolment override of the project's
 * own `exit_redirect_url`. Nullable: absent means "use the project's value"
 * (SPEC.md §3.3 create-interview request notes), never a copy of it.
 * `varchar(2048)` (gga round 3 finding 2 — this migration is unreleased,
 * edited in place rather than following up with a second migration):
 * `CreateInterviewRequest` validates `max:2048`, matching the admin
 * `StoreProjectRequest`/`UpdateProjectRequest` validation rule for the
 * SAME-shaped `projects.exit_redirect_url` field — but a plain `string()`
 * column defaults to `varchar(255)`, narrower than what the validated
 * request accepts, which would 500 on insert (value too long) instead of
 * the caller ever seeing the intended `422`. (The admin `projects` table
 * carries the identical, pre-existing narrower-column gap on its own
 * `exit_redirect_url`/`error_redirect_url` — out of scope here: those
 * migrations already shipped, and fixing them is not part of this change.)
 *
 * `mode` — `live`|`test`, reusing the identical `live|test` CHECK shape
 * `2026_09_24_095945_add_public_api_fields_to_api_clients_table` already
 * established for `api_clients.mode` (same two values, same reason: the
 * BEAI Public API test-mode key that creates this row is the ONLY source of
 * this column's value — `App\Enums\ApiKeyMode` is the shared, single source
 * of truth for both). Defaults `live`: every participant created through
 * any OTHER path (backoffice, SSO ingress before this column existed) is a
 * live-mode candidate. G-22 records that this column does not yet
 * PARTITION reads/writes by mode (only `api_clients.mode` does, since a
 * test-mode key cannot reach `/v1/interviews` until step 9's tenant-table
 * scoping lands) — this migration only adds the column and stamps it
 * correctly on creation from `App\Actions\PublicApi\EnrolCandidate`.
 *
 * `session_token_jti` — the `jti` of the CURRENT, unconsumed session token
 * (SPEC.md §3.5), nullable. Minting a new token overwrites this column
 * (revoking whatever `jti` it held), and the embed exchange clears it back
 * to `null` on consumption. No index: the column IS high-cardinality (a
 * fresh ULID per mint, never repeated), but nothing ever looks a row UP by
 * `jti` — every real lookup (`SessionTokenController::store()`, `Embed\
 * ExchangeController::exchange()`) resolves the participant FIRST, by
 * `organization_id` + `public_id` (both already indexed), and only THEN
 * compares the presented token's `jti` against that one row's
 * `session_token_jti` in PHP — an equality check on an already-loaded
 * value, not a `WHERE session_token_jti = ?` query a dedicated index could
 * ever serve (step 5 review follow-up, item 13 — the original docblock's
 * "low-cardinality" reasoning was backwards).
 *
 * Deploy window (step 5 review follow-up, item 18, G-45): this migration's
 * `public_id` NOT NULL step assumes every INSERT reaching `participants`
 * from this point on already sets it — true for `App\Models\Concerns\
 * HasPublicId`'s `creating` hook, which every code path in THIS release
 * uses. A rolling deploy that runs this migration against instances still
 * serving the PREVIOUS release (code that inserts a `participants` row
 * without `HasPublicId`) fails those inserts closed at the database (a
 * `NOT NULL` violation, `23502`) for the remainder of that window, rather
 * than silently admitting a row this migration's own backfill will never
 * revisit. This migration MUST run only after the new code is live on
 * every instance that can insert into `participants` — never during, or
 * ahead of, that rollout. No code change addresses this; it is an
 * operational ordering constraint, recorded here because nothing else
 * enforces it.
 */
return new class extends Migration
{
    use ChecksColumnNullability;
    use RepairsInvalidUniqueIndex;

    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table): void {
            if (! Schema::hasColumn('participants', 'public_id')) {
                $table->char('public_id', 26)->nullable()->after('id');
            }

            if (! Schema::hasColumn('participants', 'metadata')) {
                $table->jsonb('metadata')->nullable()->after('language');
            }

            if (! Schema::hasColumn('participants', 'exit_redirect_url')) {
                $table->string('exit_redirect_url', 2048)->nullable()->after('metadata');
            }

            if (! Schema::hasColumn('participants', 'mode')) {
                $table->string('mode', 4)->default('live')->after('exit_redirect_url');
            }

            if (! Schema::hasColumn('participants', 'session_token_jti')) {
                $table->string('session_token_jti')->nullable()->after('mode');
            }
        });

        $this->backfillPublicId();

        if (! $this->columnIsNotNull('participants', 'public_id')) {
            Schema::table('participants', function (Blueprint $table): void {
                $table->char('public_id', 26)->nullable(false)->change();
            });
        }

        $this->addPublicIdUniqueIndex();

        $this->addModeCheckConstraint();
    }

    /**
     * `participants` is a hot path (step 5 review follow-up, item 17) — the
     * default `ADD CONSTRAINT ... UNIQUE` Laravel's `unique()` blueprint
     * method issues takes an `ACCESS EXCLUSIVE` lock (blocking every
     * concurrent read AND write) for as long as it takes Postgres to build
     * the index over the WHOLE table. `CREATE UNIQUE INDEX CONCURRENTLY`
     * builds it without that lock, at the cost of needing its own,
     * non-transactional DDL statement — Postgres refuses it outright
     * (`CREATE INDEX CONCURRENTLY cannot run inside a transaction block`)
     * whenever one is already open.
     *
     * `$withinTransaction = false` on this class means a REAL `php artisan
     * migrate` run never opens one, so `CONCURRENTLY` always applies in
     * production. `tests/Feature/Migration/PublicApiMigrationsRerunTest`
     * re-invokes this same `up()` a SECOND time from inside
     * `RefreshDatabase`'s own wrapping test transaction (`Feature/Migration`
     * is configured that way in `tests/Pest.php`, load-bearing for those
     * tests) — `DB::transactionLevel() > 0` there, and falls back to the
     * plain blueprint form rather than erroring: the ONLY thing that matters
     * inside a test's own short-lived, already-isolated transaction is that
     * the index ends up existing, never lock duration.
     *
     * `Schema::hasIndex()` reports an index as present purely by NAME — it
     * has no idea Postgres itself considers this one broken (step 6 review
     * follow-up, Part A item 2). A `CREATE UNIQUE INDEX CONCURRENTLY` that
     * failed partway (the migration process killed mid-build, or a
     * conflicting row discovered during the concurrent scan) leaves EXACTLY
     * this shape: the index exists, by that name, but Postgres marks
     * `pg_index.indisvalid = false` and it enforces nothing — the column is
     * silently NOT unique from that point on, and every future rerun of this
     * migration would keep reporting "done" (`hasIndex()` says so) forever.
     * Checked here, ahead of the `hasIndex()` early return, so an invalid
     * index is dropped and the method falls through to rebuild it exactly as
     * if it had never existed.
     *
     * The invalid-index check-and-rebuild itself is
     * `RepairsInvalidUniqueIndex::repairInvalidUniqueIndex()` (step 6 review
     * follow-up, finding 2) — extracted so
     * `2026_09_24_150100_repair_invalid_participants_public_id_index` can
     * reuse it as a standalone, separately-runnable repair for an
     * environment that already ran THIS migration's `up()` before that
     * handling existed.
     */
    private function addPublicIdUniqueIndex(): void
    {
        $this->repairInvalidUniqueIndex('participants', 'public_id', 'participants_public_id_unique');

        if (Schema::hasIndex('participants', 'participants_public_id_unique')) {
            return;
        }

        $this->rebuildUniqueIndex('participants', 'public_id', 'participants_public_id_unique');
    }

    /**
     * `ADD CONSTRAINT ... CHECK (...)` in its plain form takes an
     * `ACCESS EXCLUSIVE` lock for as long as Postgres needs to verify the
     * check against every EXISTING row. Adding it `NOT VALID` first skips
     * that scan (the constraint is enforced for every new/updated row
     * immediately, but existing rows are not yet checked), which makes the
     * `ADD CONSTRAINT` step itself near-instant; the separate `VALIDATE
     * CONSTRAINT` statement then scans the table for real but only needs a
     * `SHARE UPDATE EXCLUSIVE` lock — concurrent reads AND writes proceed
     * throughout (step 5 review follow-up, item 17). Unlike
     * `addPublicIdUniqueIndex()`'s `CONCURRENTLY` index, BOTH statements
     * here are ordinary transactional DDL — no `DB::transactionLevel()`
     * branch is needed; this shape is safe inside the rerun test's wrapping
     * transaction exactly as it is in a real, transaction-free migration
     * run.
     */
    private function addModeCheckConstraint(): void
    {
        if ($this->tableConstraintExists('participants', 'participants_mode_check')) {
            return;
        }

        DB::statement(
            "ALTER TABLE participants ADD CONSTRAINT participants_mode_check
             CHECK (mode IN ('live', 'test')) NOT VALID"
        );

        DB::statement('ALTER TABLE participants VALIDATE CONSTRAINT participants_mode_check');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE participants DROP CONSTRAINT IF EXISTS participants_mode_check');

        // `dropUnique()` alone (the previous shape) assumes
        // `participants_public_id_unique` is a CONSTRAINT-backed unique —
        // true only when `addPublicIdUniqueIndex()` took its in-transaction
        // fallback. Outside a transaction (the real, `$withinTransaction =
        // false` migration path) it is a bare `CREATE UNIQUE INDEX
        // CONCURRENTLY` index instead, and `ALTER TABLE ... DROP
        // CONSTRAINT` on that fails (`SQLSTATE[42704]`) since no
        // constraint by that name exists — while `DROP INDEX` on a
        // constraint-backed one fails the OTHER way (`SQLSTATE[2BP01]`,
        // "cannot drop index because constraint requires it"). Checked
        // first so `down()` uses whichever form actually produced it.
        if ($this->tableConstraintExists('participants', 'participants_public_id_unique')) {
            DB::statement('ALTER TABLE participants DROP CONSTRAINT participants_public_id_unique');
        } else {
            DB::statement('DROP INDEX IF EXISTS participants_public_id_unique');
        }

        Schema::table('participants', function (Blueprint $table): void {
            $table->dropColumn(['public_id', 'metadata', 'exit_redirect_url', 'mode', 'session_token_jti']);
        });
    }

    private function backfillPublicId(): void
    {
        // whereNull('public_id') — same idempotent-resume discipline as the
        // step 4 migration's own backfill(): a no-op when every row already
        // has one.
        DB::table('participants')->whereNull('public_id')->orderBy('id')->chunkById(500, function (Collection $rows): void {
            foreach ($rows as $row) {
                DB::table('participants')->where('id', $row->id)->update([
                    'public_id' => (string) Str::ulid(),
                ]);
            }
        });
    }
};
