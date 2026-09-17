<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create `framework_catalog_revisions` (framework-catalogue-authoring PR1, D1).
 *
 * GLOBAL — platform-wide, not tenant-scoped. A revision is the unit every
 * catalogue-content table (`framework_roles`, `framework_competencies`,
 * `framework_bars_indicators`, `framework_role_competency`,
 * `framework_default_questions`) points at via `revision_id`, and the row
 * `framework_versions.revision_id` resolves to.
 *
 * Two partial unique indexes make the singular language in the spec ("*the*
 * open draft", "*the* baseline") structural rather than conventional: at
 * most one `draft` row can exist at a time, and at most one `is_baseline`
 * row can exist, ever. `((true))` is the Postgres idiom for "index every row
 * that satisfies the WHERE clause on a constant" — there is no natural
 * column to index on, only the predicate.
 *
 * `state` carries a real CHECK constraint, not only the inline comment —
 * this repo already enforces string-enum columns this way in at least ten
 * other places (`notification_logs_suppression_reason_check`,
 * `indicator_scores_unassessable_reason_check`,
 * `webhook_deliveries_skip_reason_check`, `ai_requests_failure_reason_check`,
 * `catalog_meta_singleton_id_check`, …). Without it, a row with
 * `state = 'Draft'` (wrong case, or any other stray value) satisfies NEITHER
 * `WHERE state = 'draft'` in the partial index NOR
 * `$revision->state === 'draft'` in `FrameworkVersion::refuseDraftRevisionTarget()`
 * — a mutable revision both the index and the pin guard would silently wave
 * through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('framework_catalog_revisions', function (Blueprint $table): void {
            $table->id();
            $table->string('state')->default('draft'); // enum: draft|published — CHECK below
            $table->boolean('is_baseline')->default(false);
            $table->string('label')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement(
            "ALTER TABLE framework_catalog_revisions
             ADD CONSTRAINT framework_catalog_revisions_state_check CHECK (state IN ('draft', 'published'))"
        );

        DB::statement(
            'CREATE UNIQUE INDEX framework_catalog_revisions_one_draft
             ON framework_catalog_revisions ((true)) WHERE state = \'draft\''
        );
        DB::statement(
            'CREATE UNIQUE INDEX framework_catalog_revisions_one_baseline
             ON framework_catalog_revisions ((true)) WHERE is_baseline'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('framework_catalog_revisions');
    }
};
