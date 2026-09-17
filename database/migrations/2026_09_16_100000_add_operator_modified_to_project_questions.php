<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance for a `project_questions` row: was it typed by an operator, or
 * copied from a catalogue default (framework-catalogue-authoring PR5, D9)?
 *
 * A FLAG, NEVER A COMPARISON. Text equality against the catalogue default was
 * rejected explicitly: the superadmin can change the default later, at which
 * point a row that was never edited starts comparing unequal and a row that
 * was edited back to the old default starts comparing equal. A flag records
 * what HAPPENED; a comparison guesses, and guesses differently over time.
 *
 * BACKFILLED TRUE, UNCONDITIONALLY. Every row that exists at migration time —
 * live or soft-deleted — was typed by an operator: `ApplyCompetencySelection`
 * (the only automated writer) does not exist before this PR, so there is no
 * prior auto-fill to distinguish from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_questions', function (Blueprint $table): void {
            $table->boolean('operator_modified')->default(false);
        });

        DB::table('project_questions')->update(['operator_modified' => true]);
    }

    public function down(): void
    {
        Schema::table('project_questions', function (Blueprint $table): void {
            $table->dropColumn('operator_modified');
        });
    }
};
