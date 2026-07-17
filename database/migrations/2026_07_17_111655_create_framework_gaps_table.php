<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Create `framework_gaps` table (C3 Framework Catalog).
 *
 * Queryable authoring task registry — gaps are structured, not silent.
 * kind: role_no_bars | competency_no_bars | missing_role_meta | missing_translation | missing_potential_competency
 * role_code / competency_code: nullable (e.g. missing_translation has both null).
 *
 * CRITICAL: unique(kind, role_code, competency_code) declared NULLS NOT DISTINCT.
 * Standard PostgreSQL unique indexes treat NULLs as distinct — without NULLS NOT DISTINCT,
 * null-containing rows (role_no_bars with null competency_code, missing_translation with both null)
 * would NOT be deduplicated at DB level. This is the DB-level idempotency guard.
 * The seeder uses updateOrCreate as belt-and-suspenders on top.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('framework_gaps', function (Blueprint $table): void {
            $table->id();
            $table->string('kind');
            $table->string('role_code')->nullable();
            $table->string('competency_code')->nullable();
            $table->text('note')->nullable();
            $table->string('status')->default('pending_authoring');
            $table->timestamps();
            $table->index(['kind', 'role_code']);
        });

        // NULLS NOT DISTINCT unique index for correct idempotency when any column is null.
        // PostgreSQL 15+ / 16+ syntax. Standard unique indexes treat NULLs as distinct,
        // which would allow duplicate gap rows when role_code or competency_code is null.
        DB::statement(
            'CREATE UNIQUE INDEX framework_gaps_kind_role_competency_unique '
            .'ON framework_gaps (kind, role_code, competency_code) '
            .'NULLS NOT DISTINCT'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('framework_gaps');
    }
};
