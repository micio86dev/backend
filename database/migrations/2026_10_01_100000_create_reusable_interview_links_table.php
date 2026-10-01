<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `reusable_interview_links` (reusable-interview-links, design AD-3): a
 * non-expiring, revocable entry link a calling team can hand to MANY candidates
 * at once, in contrast to the single-use, 30-minute SSO link minted per person.
 *
 * Tenant-scoped (`App\Models\ReusableInterviewLink extends TenantModel`):
 * `organization_id` is stamped by `TenantScoped::creating` and is never
 * mass-assignable. `public_id` (`char(26)`, `rlk_` on the way out via
 * `App\Support\PublicApi\PublicId`) is `NOT NULL UNIQUE` from the start: a new
 * table has no pre-`public_id` history to backfill.
 *
 * The secret is NEVER stored. `token_hash` is the lowercase hex SHA-256 of the
 * whole `beai_rl_...` token (`char(64)`, UNIQUE, CHECKed against
 * `^[0-9a-f]{64}$`); `token_prefix` is only the marker plus its first 8 random
 * characters, so an operator can tell two links apart in a list. The token
 * itself exists once, in the creation response.
 *
 * No expiry column and no `revoked_*` pair, on purpose: the link never expires
 * (a bounded window is the project's own `deadline_at`), and "switched off" is
 * one fact, `disabled_at` (+ `disabled_by`). A second "revoked" vocabulary would
 * be two sources of truth. There is no re-enable and no hard delete path.
 * `uses_count` / `last_used_at` are maintained under a row lock by the
 * redemption action; the CHECK keeps the counter from ever going negative.
 *
 * Indexes: the list index `(organization_id, project_id, created_at)` leads with
 * `organization_id` (D22: every query is tenant-scoped first). The `token_hash`
 * UNIQUE is the one documented exception to that rule, exactly like
 * `api_clients.key_hash`: a redemption looks the link up by hash BEFORE any
 * tenant is known, so the lookup key cannot lead with an organisation. `public_id`
 * UNIQUE is the externally visible id.
 *
 * Foreign keys: project and organisation deletion cascade to the links (a link
 * to a project that no longer exists is meaningless); `created_by` and
 * `disabled_by` only NULL on user deletion, so removing an operator never
 * silently removes a live entry link.
 *
 * A new, empty table is an ordinary transactional create: no `CONCURRENTLY`, no
 * invalid-index repair. `up()` still returns early when the table exists so a
 * rerun leaves rows and structure alone, and `down()` is `dropIfExists()`, which
 * removes the CHECKs, uniques and indexes with the table.
 *
 * Deploy order: this migration runs before the code that reads or writes the
 * table serves traffic. The previous release ignores an unknown table, so the
 * reverse window is safe. The `participants.reusable_interview_link_id` marker
 * column that points back at this table ships in the NEXT migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reusable_interview_links')) {
            return;
        }

        Schema::create('reusable_interview_links', function (Blueprint $table): void {
            $table->id();

            $table->char('public_id', 26);

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // An operator-facing name ("Campus drive"), shown in the links
            // list and used to name the anonymous visitors a link produces.
            $table->string('label', 120)->nullable();

            // Frozen at creation from the project's language, so a later
            // change of the project language never alters what an already
            // handed-out link renders in.
            $table->string('lang', 10);

            // Lowercase hex SHA-256 of the whole raw token. CHECK below.
            $table->char('token_hash', 64);

            // `beai_rl_` + first 8 random characters. Identification only.
            $table->string('token_prefix', 16);

            $table->integer('uses_count')->default(0);
            $table->timestampTz('last_used_at')->nullable();

            // The ONLY off switch. NULL = active.
            $table->timestampTz('disabled_at')->nullable();
            $table->foreignId('disabled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestampsTz();

            $table->unique('public_id', 'reusable_interview_links_public_id_unique');

            // Documented D22 exception: looked up by hash before any tenant
            // is known (see class doc).
            $table->unique('token_hash', 'reusable_interview_links_token_hash_unique');

            // The project's links list, per organisation, in creation order.
            $table->index(['organization_id', 'project_id', 'created_at'], 'reusable_interview_links_org_project_index');
        });

        // Raw-DDL CHECK constraints — make illegal states unrepresentable
        // (same precedent as `exports_*_check`). Nowdoc: the SQL carries a
        // regex `$` and a LIKE escape `\`, neither of which PHP may touch.
        DB::statement(<<<'SQL'
            ALTER TABLE reusable_interview_links
            ADD CONSTRAINT reusable_interview_links_token_hash_check
            CHECK (token_hash ~ '^[0-9a-f]{64}$')
            SQL);

        // `_` is a LIKE wildcard: escaped, so `beaiXrlX...` does not pass.
        DB::statement(<<<'SQL'
            ALTER TABLE reusable_interview_links
            ADD CONSTRAINT reusable_interview_links_token_prefix_check
            CHECK (token_prefix LIKE 'beai\_rl\_%')
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE reusable_interview_links
            ADD CONSTRAINT reusable_interview_links_uses_count_check
            CHECK (uses_count >= 0)
            SQL);
    }

    public function down(): void
    {
        // CHECK constraints, uniques and indexes are dropped with the table.
        Schema::dropIfExists('reusable_interview_links');
    }
};
