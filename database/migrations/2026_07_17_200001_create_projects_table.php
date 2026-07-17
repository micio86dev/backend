<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            $table->unique(['organization_id', 'slug']); // slug unique per org (partial — model handles soft-delete exclusion)
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'role_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
