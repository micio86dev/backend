<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `framework_bars_indicators.role_id` becomes NULLABLE, meaning "not
 * role-scoped" (potential-competencies-and-authored-questions, AD-1).
 *
 * BARS indicators are the scoring instrument, and a `potential` project has
 * `role_code = null` by rule (StoreProjectRequest::validatePotential enforces
 * it). With a NOT NULL role there was no way to store an indicator for MTG or
 * LAT at all — which is why those competencies were never authored, and why
 * `assessment_type: potential` has never been usable.
 *
 * A sixth `POTENTIAL` role in `framework_roles` would also have worked and was
 * rejected: CLAUDE.md fixes the roles at exactly five as a binding constraint,
 * and every read that lists roles would have had to learn to hide one. A
 * nullable FK states the true thing — this indicator belongs to a competency
 * and to no role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('framework_bars_indicators', function (Blueprint $table): void {
            $table->foreignId('role_id')->nullable()->change();
        });

        // The existing UNIQUE (role_id, competency_id, position) does NOT
        // constrain the role-less rows: Postgres treats NULLs as distinct, so
        // two indicators for the same competency at the same position would
        // both be accepted and the catalogue would silently gain a duplicate.
        // A partial index restores the guarantee exactly where the composite
        // one stops applying.
        DB::statement(
            'CREATE UNIQUE INDEX framework_bars_indicators_roleless_position_unique
             ON framework_bars_indicators (competency_id, "position")
             WHERE role_id IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS framework_bars_indicators_roleless_position_unique');

        // Role-less rows cannot survive a NOT NULL column, and they are
        // catalogue data the seeder rebuilds — deleted rather than left to
        // fail the ALTER with a constraint violation that reads as corruption.
        DB::table('framework_bars_indicators')->whereNull('role_id')->delete();

        Schema::table('framework_bars_indicators', function (Blueprint $table): void {
            $table->foreignId('role_id')->nullable(false)->change();
        });
    }
};
