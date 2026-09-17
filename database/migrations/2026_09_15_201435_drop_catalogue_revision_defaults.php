<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop the literal `DEFAULT <baseline id>` PR1's backfill migration set on
 * `revision_id`, now that every writer this PR touches always names a
 * revision explicitly (review advisory R3-001).
 *
 * SCOPED TO TWO OF THE FOUR CATALOG-CONTENT TABLES — `framework_roles` and
 * `framework_competencies` ONLY. Both were verified safe: every writer
 * either already names `revision_id` explicitly, or goes through
 * `Role`/`Competency::booted()`'s own `creating` listener — NOT the
 * factory, deliberately (framework-catalogue-authoring PR3b, H10; see
 * `RoleFactory`/`CompetencyFactory`'s own docblocks) — which defaults
 * `revision_id` to the baseline the SAME way for every Eloquent creation
 * path, factory or otherwise, so every existing call site keeps working
 * unmodified. Audited across the whole suite for this PR.
 *
 * `framework_bars_indicators` IS NOT INCLUDED — attempted, and reverted
 * after measurement, not skipped for lack of trying. `BarsIndicator` has no
 * Eloquent factory, so the suite constructs it via many independent,
 * per-test-file helper functions (e.g. `tests/Unit/C8/
 * SystemPromptComposerTest.php`'s own `composerMakeIndicator()`) using
 * `new BarsIndicator; $indicator->forceFill([...])` or raw `DB::table(...)
 * ->insert(...)` — neither shape is caught by a `BarsIndicator::create(`
 * or a single shared-helper grep, which is exactly how the initial
 * per-file audit undercounted the true blast radius. Dropping the default
 * broke 223 tests across the suite in a full run — call sites this PR does
 * not own, spread across dozens of files, each needing its own
 * `revision_id` added. Fixing all of them is a mechanical but genuinely
 * large change outside this PR's scope; left in place rather than done
 * half-way, per this PR's own explicit instruction to stop and report
 * rather than leave a DEFAULT removal partially done. The underlying risk
 * the DEFAULT posed — a write silently landing, unnoticed, in an already-
 * populated PUBLISHED revision — is independently closed for this table
 * anyway by `2026_09_15_201434_enforce_catalogue_published_content_
 * immutability`'s trigger, which does not depend on whether a DEFAULT
 * exists; this PR's own CRUD write path also never relies on it, since it
 * always names `revision_id` explicitly on every write.
 *
 * `framework_role_competency` is ALSO excluded, for a different, narrower
 * reason: its writes go through `Role::competencies()->sync()` — a
 * `belongsToMany` relation whose pivot columns are `position` only;
 * `revision_id` is not one of them, and making it one requires the pivot to
 * carry a value that differs PER CALL (whichever revision is being
 * written), not a fixed default — a `withPivotValue()`-style change to the
 * relation itself, out of scope here. The seeder's own `sync()` call
 * (already shipped, already tested, PR1/PR2) relies on this default landing
 * new pivot rows in the baseline, which is where it belongs for a seeder
 * that only ever targets the baseline.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE framework_roles ALTER COLUMN revision_id DROP DEFAULT');
        DB::statement('ALTER TABLE framework_competencies ALTER COLUMN revision_id DROP DEFAULT');
    }

    public function down(): void
    {
        $baselineId = DB::table('framework_catalog_revisions')->where('is_baseline', true)->value('id');

        if ($baselineId !== null) {
            DB::statement("ALTER TABLE framework_roles ALTER COLUMN revision_id SET DEFAULT {$baselineId}");
            DB::statement("ALTER TABLE framework_competencies ALTER COLUMN revision_id SET DEFAULT {$baselineId}");
        }
    }
};
