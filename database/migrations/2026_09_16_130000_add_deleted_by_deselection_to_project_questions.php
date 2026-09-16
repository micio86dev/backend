<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Z8 (R3-restore-resurrects-individually-deleted-question, REQUIRED BEFORE
 * ARCHIVE — decided by the orchestrator under the product owner's "the
 * operator's work is sacred" principle): records WHY a `project_questions`
 * row was soft-deleted, so `ApplyCompetencySelection::restore()` can tell a
 * row a DESELECTION removed apart from one an operator removed on purpose.
 *
 * Deleting is operator work exactly like writing — before this column,
 * reselecting a competency resurrected EVERY trashed row, including one the
 * operator had individually deleted, because nothing distinguished the two.
 *
 * BACKFILLED FALSE, UNCONDITIONALLY (the SAFE default, not a guess). Every
 * row already trashed at migration time is ambiguous — this column did not
 * exist to record which cause applied — and `restore()`'s new filter treats
 * `false` as "do not auto-restore". Backfilling `true` instead would risk
 * resurrecting a row an operator meant to stay gone; `false` risks nothing
 * worse than a superadmin/operator re-authoring a question that would have
 * come back on its own, which is the SAME outcome `restore()` already
 * produces for an individually-deleted row going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_questions', function (Blueprint $table): void {
            $table->boolean('deleted_by_deselection')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('project_questions', function (Blueprint $table): void {
            $table->dropColumn('deleted_by_deselection');
        });
    }
};
