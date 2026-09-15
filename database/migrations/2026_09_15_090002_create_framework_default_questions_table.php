<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create `framework_default_questions` (framework-catalogue-authoring PR1,
 * catalogue-authoring spec — "Catalogue-Level Default Questions Per
 * Competency").
 *
 * GLOBAL, revision-scoped — a superadmin-authored template a project's
 * `ApplyCompetencySelection` copies from (D10, PR5). Never read directly at
 * interview time. Brand new, empty table: unlike the four existing catalog
 * tables, `revision_id` is NOT NULL from the start — there is no pre-existing
 * data to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('framework_default_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('revision_id')->constrained('framework_catalog_revisions')->restrictOnDelete();
            $table->foreignId('competency_id')->constrained('framework_competencies')->cascadeOnDelete();
            $table->json('text');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            // Explicit short name — the auto-generated one
            // ("framework_default_questions_revision_id_competency_id_position_unique",
            // 69 chars) exceeds Postgres's 63-byte identifier limit and gets
            // silently truncated to a name nobody wrote.
            $table->unique(['revision_id', 'competency_id', 'position'], 'framework_default_questions_rev_competency_position_unique');
        });

        Schema::table('framework_default_questions', function (Blueprint $table): void {
            $table->foreign(['competency_id', 'revision_id'], 'framework_default_questions_competency_revision_fk')
                ->references(['id', 'revision_id'])->on('framework_competencies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('framework_default_questions');
    }
};
