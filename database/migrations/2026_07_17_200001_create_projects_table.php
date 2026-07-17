<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create tenant-scoped `projects` table (C4 Project Configuration).
 *
 * Extends C2 TenantModel pattern — organization_id scoped, org-lead composite indexes per D22.
 * assessment_type: 'standard'|'potential' (immutable once status=active).
 * framework_version_id: reference-pin to a locked FrameworkVersion (restrictOnDelete).
 * webhook_secret: encrypted at rest (via Eloquent encrypted cast on the model).
 * SoftDeletes: preserves audit trail and pin history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            $table->foreignId('framework_version_id')
                ->constrained('framework_versions')
                ->restrictOnDelete();

            $table->string('slug');
            $table->string('name');
            $table->string('assessment_type'); // standard|potential
            $table->string('role_code')->nullable(); // required for standard; null for potential
            $table->string('language');
            $table->string('status')->default('draft'); // draft|active|archived

            $table->unsignedTinyInteger('pause_every_n_competencies')->nullable();
            $table->unsignedSmallInteger('nudge_min_chars')->nullable();
            $table->string('exit_redirect_url')->nullable();
            $table->string('webhook_url')->nullable();
            $table->text('webhook_secret')->nullable(); // encrypted at rest via model cast

            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('goes_live_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // D22 org-lead composite indexes
            // NOTE: The slug unique index is a PARTIAL index (excludes soft-deleted rows).
            // We add it via raw SQL after table creation — see below.
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'role_code']);
        });

        // Partial unique index: slug is unique per org ONLY among non-deleted projects.
        // A slug belonging to a soft-deleted project is reusable (design decision).
        // This cannot be expressed with $table->unique() which applies to ALL rows.
        DB::statement(
            'CREATE UNIQUE INDEX projects_organization_id_slug_unique
             ON projects (organization_id, slug)
             WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        // The partial unique index is dropped automatically with the table
        Schema::dropIfExists('projects');
    }
};
