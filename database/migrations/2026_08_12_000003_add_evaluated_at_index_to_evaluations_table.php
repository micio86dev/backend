<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `(organization_id, evaluated_at)` composite index to `evaluations`
 * (backoffice-missing-pages D1/D7).
 *
 * `evaluations` today has `unique(participant_id)` and
 * `(organization_id, participant_id)` — nothing on `evaluated_at`
 * (`2026_07_22_000001_create_evaluations_table.php:65-68`). The `/reports`
 * sort and date-range filter are both on `evaluated_at`, which no existing
 * index covers. Org-first per the D22 composite-index rule.
 *
 * REQ: EvaluationIndexQuery (backoffice-missing-pages D6/D7)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluations', function (Blueprint $table): void {
            $table->index(['organization_id', 'evaluated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('evaluations', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'evaluated_at']);
        });
    }
};
