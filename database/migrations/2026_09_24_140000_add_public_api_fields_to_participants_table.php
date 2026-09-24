<?php

declare(strict_types=1);

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
 * to `null` on consumption. Never queried by anything other than the exact
 * `jti` a presented session token carries — no index beyond the implicit
 * one PostgreSQL never needs here (equality lookups on a low-cardinality,
 * per-row column gain nothing from a dedicated index).
 */
return new class extends Migration
{
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

        if (! Schema::hasIndex('participants', 'participants_public_id_unique')) {
            Schema::table('participants', function (Blueprint $table): void {
                $table->unique('public_id', 'participants_public_id_unique');
            });
        }

        if (! $this->checkConstraintExists('participants_mode_check')) {
            DB::statement(
                "ALTER TABLE participants ADD CONSTRAINT participants_mode_check
                 CHECK (mode IN ('live', 'test'))"
            );
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE participants DROP CONSTRAINT IF EXISTS participants_mode_check');

        Schema::table('participants', function (Blueprint $table): void {
            $table->dropUnique('participants_public_id_unique');
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

    /**
     * `Schema::hasColumn()` only answers "does the column exist", not
     * "is it NOT NULL" — needed here to decide whether the `->change()`
     * step below has already run on a rerun.
     */
    private function columnIsNotNull(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $columnDefinition) {
            if ($columnDefinition['name'] === $column) {
                return ! $columnDefinition['nullable'];
            }
        }

        return false;
    }

    /**
     * No Laravel-native `Schema::hasCheckConstraint()` exists — queried
     * directly against `information_schema.table_constraints` so a rerun
     * does not attempt `ADD CONSTRAINT` on a name that already exists
     * (a hard Postgres error, unlike an idempotent `IF NOT EXISTS` DDL form
     * Postgres has no equivalent of for constraints).
     */
    private function checkConstraintExists(string $constraintName): bool
    {
        $rows = DB::select(
            'SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = ?',
            [$constraintName],
        );

        return $rows !== [];
    }
};
