<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the baseline revision, then tighten every `revision_id` column
 * added by the two prior migrations to NOT NULL (framework-catalogue-
 * authoring PR1, D1).
 *
 * THE WHOLE CORRECTNESS ARGUMENT. This migration creates exactly ONE
 * `framework_catalog_revisions` row (`is_baseline = true`, `state =
 * 'published'`, `published_at = now()`) and stamps its id onto every
 * EXISTING catalogue row and every EXISTING `framework_versions` row via a
 * plain UPDATE. NO anchor text is copied, rewritten, or re-keyed;
 * `framework_bars_indicators.id` values are UNCHANGED. That is what lets
 * `Evaluation.framework_version_id -> FrameworkVersion.revision_id -> the
 * same physical rows it always resolved` hold — see
 * BaselineRevisionMigrationTest.
 *
 * PUBLISHED, NOT DRAFT — this is load-bearing, not cosmetic. The baseline is
 * already-shipped, already-scored content: by definition not mutable, so it
 * belongs in the state that means that. Inserting it as `draft` was an
 * earlier defect in this same migration: `framework_catalog_revisions_one_draft`
 * permits at most ONE draft on the whole platform, so a draft baseline would
 * occupy that slot PERMANENTLY — PR 3's `OpenDraftRevision` could never open
 * a real draft, and `FrameworkVersion`'s own "refuse a draft target" guard
 * (2.8) would reject every future pin, including the installed base this
 * migration just stamped. Publishing it here is also simply true: every row
 * it points at is content that shipped and has already been scored against.
 *
 * WHY THE TIGHTENING LIVES HERE, NOT IN THE MIGRATION THAT ADDS THE COLUMN.
 * `ALTER COLUMN ... SET NOT NULL` fails if any row is still NULL, and
 * `framework_role_competency`'s new composite primary key requires its
 * columns to already be NOT NULL — both need `revision_id` populated first,
 * which needs this migration's baseline row to exist first. A single-file
 * "add nullable, backfill, tighten" sequence was rejected because
 * `framework_versions.revision_id` (added in the migration right before
 * this one) needs to exist before it can be backfilled, and stamping a
 * baseline id onto it before the id itself exists is not possible — hence
 * three migrations, each doing the smallest thing Postgres will actually
 * let it do in order.
 *
 * A literal DEFAULT is set (not just NOT NULL) on the four catalog-content
 * columns, pointing at the baseline id. Without it, this migration would be
 * the moment hundreds of already-shipped test fixtures across the suite
 * start failing `NOT NULL` on `framework_roles`/`framework_competencies`/
 * `framework_bars_indicators`/`framework_role_competency` inserts that have
 * never heard of a revision — every one of them lands in the baseline
 * revision by default, which is the correct place for content nobody has
 * told which draft to belong to. `framework_versions.revision_id` gets NO
 * such default: see the previous migration's docblock for why.
 */
return new class extends Migration
{
    public function up(): void
    {
        $baselineId = DB::table('framework_catalog_revisions')->insertGetId([
            'state' => 'published',
            'is_baseline' => true,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('framework_roles')->whereNull('revision_id')->update(['revision_id' => $baselineId]);
        DB::table('framework_competencies')->whereNull('revision_id')->update(['revision_id' => $baselineId]);
        DB::table('framework_bars_indicators')->whereNull('revision_id')->update(['revision_id' => $baselineId]);
        DB::table('framework_role_competency')->whereNull('revision_id')->update(['revision_id' => $baselineId]);
        DB::table('framework_versions')->whereNull('revision_id')->update(['revision_id' => $baselineId]);

        DB::statement("ALTER TABLE framework_roles ALTER COLUMN revision_id SET DEFAULT {$baselineId}");
        DB::statement('ALTER TABLE framework_roles ALTER COLUMN revision_id SET NOT NULL');
        DB::statement("ALTER TABLE framework_competencies ALTER COLUMN revision_id SET DEFAULT {$baselineId}");
        DB::statement('ALTER TABLE framework_competencies ALTER COLUMN revision_id SET NOT NULL');
        DB::statement("ALTER TABLE framework_bars_indicators ALTER COLUMN revision_id SET DEFAULT {$baselineId}");
        DB::statement('ALTER TABLE framework_bars_indicators ALTER COLUMN revision_id SET NOT NULL');
        DB::statement("ALTER TABLE framework_role_competency ALTER COLUMN revision_id SET DEFAULT {$baselineId}");
        DB::statement('ALTER TABLE framework_role_competency ALTER COLUMN revision_id SET NOT NULL');

        // BarsIndicator's role side of the composite FK. Deferred to here
        // (rather than the earlier migration) because it needs `revision_id`
        // to already be NOT NULL for the same "primary/composite key columns
        // must be populated first" reason as the pivot's PK swap below.
        // role_id itself stays NULLABLE (MTG/LAT are role-less, 2026_09_02) —
        // MATCH SIMPLE skips the FK check entirely on a NULL role_id, so a
        // role-less indicator's revision_id is validated by the
        // competency-side composite FK alone.
        Schema::table('framework_bars_indicators', function (Blueprint $table): void {
            $table->foreign(['role_id', 'revision_id'], 'framework_bars_indicators_role_revision_fk')
                ->references(['id', 'revision_id'])->on('framework_roles')->cascadeOnDelete();
        });

        // Rebuild the natural-key uniqueness with revision_id prepended —
        // the same partial-index device 2026_09_02_170332 already uses for
        // role-less rows, carried forward.
        DB::statement(
            'ALTER TABLE framework_bars_indicators
             DROP CONSTRAINT framework_bars_indicators_role_id_competency_id_position_unique'
        );
        DB::statement('DROP INDEX framework_bars_indicators_roleless_position_unique');
        // Explicit short name — "framework_bars_indicators_revision_role_competency_position_unique"
        // is 66 chars, over Postgres's 63-byte identifier limit, and gets
        // silently truncated (to a name ending in a bare underscore) rather
        // than rejected.
        DB::statement(
            'CREATE UNIQUE INDEX framework_bars_indicators_rev_role_comp_position_unique
             ON framework_bars_indicators (revision_id, role_id, competency_id, "position")'
        );
        DB::statement(
            'CREATE UNIQUE INDEX framework_bars_indicators_roleless_position_unique
             ON framework_bars_indicators (revision_id, competency_id, "position") WHERE role_id IS NULL'
        );

        // framework_role_competency's primary key changes shape — dropped
        // and recreated (design D1's "where beta is taken advantage of")
        // rather than an ALTER dance, now that every row already carries its
        // revision_id.
        DB::statement('ALTER TABLE framework_role_competency DROP CONSTRAINT framework_role_competency_pkey');
        DB::statement(
            'ALTER TABLE framework_role_competency DROP CONSTRAINT framework_role_competency_role_id_competency_id_unique'
        );
        DB::statement(
            'ALTER TABLE framework_role_competency
             ADD PRIMARY KEY (revision_id, role_id, competency_id)'
        );
    }

    /**
     * Restores a SINGLE-revision schema only. Reverting after a second
     * revision exists is a reseed, not a rollback — legitimate ONLY because
     * the product is in beta (D1). See BaselineRevisionRollbackTest, which
     * documents this limitation as an explicit assertion rather than an
     * assumption.
     *
     * Does not need to null out `revision_id` values or delete the baseline
     * row: the two migrations that added these columns each drop them
     * entirely in their own `down()`, and dropping a column drops every
     * constraint that depends on it: cascades take the composite FKs, the
     * NOT NULL, and the DEFAULT with them. This migration's `down()` only
     * needs to restore the constraint SHAPES those later `down()` calls
     * expect to find un-composite again.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE framework_role_competency DROP CONSTRAINT framework_role_competency_pkey');
        DB::statement(
            'ALTER TABLE framework_role_competency ADD CONSTRAINT framework_role_competency_pkey PRIMARY KEY (id)'
        );
        DB::statement(
            'ALTER TABLE framework_role_competency
             ADD CONSTRAINT framework_role_competency_role_id_competency_id_unique UNIQUE (role_id, competency_id)'
        );

        DB::statement('DROP INDEX framework_bars_indicators_rev_role_comp_position_unique');
        DB::statement('DROP INDEX framework_bars_indicators_roleless_position_unique');
        // Recreated as a proper CONSTRAINT (not a bare index) — matches what
        // the original `$table->unique(...)` produced, and what this
        // migration's own up() expects to find (`DROP CONSTRAINT`) if it
        // ever runs again after this down().
        DB::statement(
            'ALTER TABLE framework_bars_indicators
             ADD CONSTRAINT framework_bars_indicators_role_id_competency_id_position_unique
             UNIQUE (role_id, competency_id, "position")'
        );
        DB::statement(
            'CREATE UNIQUE INDEX framework_bars_indicators_roleless_position_unique
             ON framework_bars_indicators (competency_id, "position") WHERE role_id IS NULL'
        );

        Schema::table('framework_bars_indicators', function (Blueprint $table): void {
            $table->dropForeign('framework_bars_indicators_role_revision_fk');
        });

        DB::statement('ALTER TABLE framework_role_competency ALTER COLUMN revision_id DROP NOT NULL');
        DB::statement('ALTER TABLE framework_role_competency ALTER COLUMN revision_id DROP DEFAULT');
        DB::statement('ALTER TABLE framework_bars_indicators ALTER COLUMN revision_id DROP NOT NULL');
        DB::statement('ALTER TABLE framework_bars_indicators ALTER COLUMN revision_id DROP DEFAULT');
        DB::statement('ALTER TABLE framework_competencies ALTER COLUMN revision_id DROP NOT NULL');
        DB::statement('ALTER TABLE framework_competencies ALTER COLUMN revision_id DROP DEFAULT');
        DB::statement('ALTER TABLE framework_roles ALTER COLUMN revision_id DROP NOT NULL');
        DB::statement('ALTER TABLE framework_roles ALTER COLUMN revision_id DROP DEFAULT');

        // The baseline row itself is removed when the earlier migration's
        // down() drops the `framework_catalog_revisions` table entirely —
        // no explicit DELETE needed here.
    }
};
