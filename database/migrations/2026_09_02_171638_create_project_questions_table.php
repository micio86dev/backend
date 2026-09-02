<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The PREDEFINED questions an interview opens each competency with
 * (potential-competencies-and-authored-questions, AD-2/AD-3/AD-4).
 *
 * They were never stored. `SystemPromptComposer` told the LLM to open with a
 * question and add follow-ups, all derived from the competency's BARS
 * indicators, so an operator could not see, edit or reorder any of it — even
 * though the binding domain doc has always specified otherwise:
 *
 *   standard  — "The first question per competency MAY BE PREDEFINED; the
 *                following ones are decided by the AI in real time"
 *   potential — "4 PREDEFINED QUESTIONS per competency, followed by AI
 *                follow-ups"
 *
 * ONE ordered list serves both. How many entries are valid is a function of
 * the assessment type, which is a validation rule and belongs beside the other
 * type invariants in the FormRequests — not in the storage layer, where it
 * would have to be re-checked on every read.
 *
 * PER PROJECT, not per framework version. A question is an operator's
 * phrasing, not domain content; on the framework version it would inherit the
 * catalogue's immutability (ruling 3 pins `framework_version` at project
 * creation and never retargets it), and a typo could never be fixed on a live
 * project.
 *
 * SOFT DELETED, for a specific reason rather than as a default: a deleted
 * question is still referenced by interviews already conducted under it, and a
 * hard delete would leave an existing transcript unexplainable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_questions', function (Blueprint $table): void {
            $table->id();

            // Denormalised from the project so every tenant-scoped query can
            // filter without a join — the same shape TenantModel expects
            // everywhere else, and what makes the composite index below lead
            // with organization_id per CLAUDE.md.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Restricted, not cascaded: a competency is catalogue data shared
            // by every tenant, and deleting one must not silently erase an
            // operator's authored questions across unrelated organizations.
            $table->foreignId('competency_id')
                ->constrained('framework_competencies')
                ->restrictOnDelete();

            // The same locale-map shape the catalogue uses (`{"en": …, "it": …}`),
            // because i18n is mandatory it/en and a question must reach the
            // candidate in the project's language.
            $table->json('text');

            $table->unsignedInteger('position')->default(0);

            $table->softDeletes();
            $table->timestamps();

            // Leads with organization_id (CLAUDE.md, multi-tenancy).
            $table->index(['organization_id', 'project_id', 'competency_id'], 'project_questions_tenant_idx');
        });

        // Position is unique WITHIN a competency of a project, and only among
        // the live rows: a soft-deleted question keeps its position, and
        // without the partial predicate it would block the slot forever.
        DB::statement(
            'CREATE UNIQUE INDEX project_questions_position_unique
             ON project_questions (project_id, competency_id, "position")
             WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('project_questions');
    }
};
