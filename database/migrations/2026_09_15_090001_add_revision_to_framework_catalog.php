<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `revision_id` to the four catalog-content tables (framework-catalogue-
 * authoring PR1, D1). Composite FKs, added here while the column is still
 * NULLABLE, are what make cross-revision row mixing a schema error rather
 * than a test assertion.
 *
 * NULLABLE HERE ON PURPOSE — tightened to NOT NULL two migrations later, in
 * `*_backfill_baseline_revision.php`. `framework_roles` and
 * `framework_competencies` already hold rows (seeded catalogue content) at
 * this point in a real deploy; a NOT NULL column with no default cannot be
 * added to a populated table, and there is no revision row to point at yet
 * — that row is created by the backfill migration. A composite FK/unique
 * constraint is not violated while `revision_id` is NULL: Postgres MATCH
 * SIMPLE semantics skip the FK check entirely when any column in the key is
 * NULL, so adding these constraints now (rather than after backfill) is
 * safe and lets this migration state the full target shape of every
 * constraint in one place. Only the NOT NULL tightening and
 * `framework_role_competency`'s primary-key swap need to wait for values to
 * exist — a primary key's columns must be NOT NULL by definition.
 *
 * `code` moves from a GLOBAL unique to `UNIQUE (revision_id, code)` — two
 * revisions may each have their own "ICO" role and "COM" competency without
 * colliding, because a draft is a full row-set clone (D1), never a delta.
 *
 * NO ROW IS COPIED by this migration or any other in this PR — only columns
 * and constraints change shape. The zero-copy property is what preserves
 * the meaning of every already-scored Evaluation (see
 * BaselineRevisionMigrationTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('framework_roles', function (Blueprint $table): void {
            $table->foreignId('revision_id')->nullable()->after('id')
                ->constrained('framework_catalog_revisions')->restrictOnDelete();
        });
        Schema::table('framework_competencies', function (Blueprint $table): void {
            $table->foreignId('revision_id')->nullable()->after('id')
                ->constrained('framework_catalog_revisions')->restrictOnDelete();
        });
        Schema::table('framework_bars_indicators', function (Blueprint $table): void {
            $table->foreignId('revision_id')->nullable()->after('id')
                ->constrained('framework_catalog_revisions')->restrictOnDelete();
        });
        Schema::table('framework_role_competency', function (Blueprint $table): void {
            $table->foreignId('revision_id')->nullable()->after('id')
                ->constrained('framework_catalog_revisions')->restrictOnDelete();
        });

        // `UNIQUE (id, revision_id)` — redundant against the primary key,
        // and that is exactly what makes it a legal composite FK target.
        Schema::table('framework_roles', function (Blueprint $table): void {
            $table->unique(['id', 'revision_id'], 'framework_roles_id_revision_unique');
            $table->dropUnique(['code']);
            $table->unique(['revision_id', 'code'], 'framework_roles_revision_code_unique');
        });
        Schema::table('framework_competencies', function (Blueprint $table): void {
            $table->unique(['id', 'revision_id'], 'framework_competencies_id_revision_unique');
            $table->dropUnique(['code']);
            $table->unique(['revision_id', 'code'], 'framework_competencies_revision_code_unique');
        });

        // Composite FKs: a row whose role_id/competency_id belongs to
        // revision 1 and whose own revision_id says 2 is refused by
        // PostgreSQL, not by application code.
        Schema::table('framework_role_competency', function (Blueprint $table): void {
            $table->foreign(['role_id', 'revision_id'], 'framework_role_competency_role_revision_fk')
                ->references(['id', 'revision_id'])->on('framework_roles')->cascadeOnDelete();
            $table->foreign(['competency_id', 'revision_id'], 'framework_role_competency_competency_revision_fk')
                ->references(['id', 'revision_id'])->on('framework_competencies')->cascadeOnDelete();
        });
        Schema::table('framework_bars_indicators', function (Blueprint $table): void {
            $table->foreign(['competency_id', 'revision_id'], 'framework_bars_indicators_competency_revision_fk')
                ->references(['id', 'revision_id'])->on('framework_competencies')->cascadeOnDelete();
        });
        // The role_id side of BarsIndicator's composite FK is added in the
        // backfill migration: role_id is NULLABLE on this table (MTG/LAT are
        // role-less by design, 2026_09_02_170332), and a composite FK across
        // a nullable column together with the NOT-NULL-tightened revision_id
        // needs the same "values must already exist" ordering as the rest
        // of the tightening pass, so it is grouped there instead of split
        // across two migrations for no reason.
    }

    public function down(): void
    {
        Schema::table('framework_bars_indicators', function (Blueprint $table): void {
            $table->dropForeign('framework_bars_indicators_competency_revision_fk');
        });
        Schema::table('framework_role_competency', function (Blueprint $table): void {
            $table->dropForeign('framework_role_competency_role_revision_fk');
            $table->dropForeign('framework_role_competency_competency_revision_fk');
        });

        Schema::table('framework_roles', function (Blueprint $table): void {
            $table->dropUnique('framework_roles_revision_code_unique');
            $table->unique('code');
            $table->dropUnique('framework_roles_id_revision_unique');
        });
        Schema::table('framework_competencies', function (Blueprint $table): void {
            $table->dropUnique('framework_competencies_revision_code_unique');
            $table->unique('code');
            $table->dropUnique('framework_competencies_id_revision_unique');
        });

        Schema::table('framework_role_competency', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('revision_id');
        });
        Schema::table('framework_bars_indicators', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('revision_id');
        });
        Schema::table('framework_competencies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('revision_id');
        });
        Schema::table('framework_roles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('revision_id');
        });
    }
};
