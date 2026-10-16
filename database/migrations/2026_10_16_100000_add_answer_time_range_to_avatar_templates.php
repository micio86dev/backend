<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The length of a "good" answer, per avatar template (answer-time-range).
 *
 * The candidate's call panel shows a bar for the current answer; these two numbers are the range
 * it highlights. Guidance only: nothing ends or shortens an answer. The DB default fills every
 * existing row, platform rows included, so no backfill is needed and no row is ever NULL.
 *
 * The CHECK mirrors the write-side rule (10..600 seconds, min below max) so a path that bypasses
 * the form requests, such as the import or a raw insert, cannot store a range the apps would
 * render as a nonsense bar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avatar_templates', function (Blueprint $table): void {
            $table->unsignedSmallInteger('answer_min_seconds')->default(90);
            $table->unsignedSmallInteger('answer_max_seconds')->default(150);
        });

        DB::statement(
            'ALTER TABLE avatar_templates ADD CONSTRAINT avatar_templates_answer_range
             CHECK (answer_min_seconds >= 10 AND answer_max_seconds <= 600 AND answer_min_seconds < answer_max_seconds)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE avatar_templates DROP CONSTRAINT IF EXISTS avatar_templates_answer_range');

        Schema::table('avatar_templates', function (Blueprint $table): void {
            $table->dropColumn(['answer_min_seconds', 'answer_max_seconds']);
        });
    }
};
