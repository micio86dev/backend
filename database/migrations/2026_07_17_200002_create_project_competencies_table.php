<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create `project_competencies` normalized pivot table (C4 Project Configuration).
 *
 * No organization_id — accessed only through the TenantScoped Project parent
 * relationship, so it inherits org scoping implicitly (D22 exemption; see design.md).
 *
 * Columns:
 *   - id: explicit PK for future pivot-model (using()) support
 *   - project_id: FK → projects (cascadeOnDelete)
 *   - competency_id: FK → framework_competencies (restrictOnDelete — protects against catalog deletions)
 *   - position: ordering within the project's competency list
 *
 * No timestamps (matches framework_role_competency pivot precedent from C3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_competencies', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->foreignId('competency_id')
                ->constrained('framework_competencies')
                ->restrictOnDelete();

            $table->unsignedInteger('position')->default(0);

            $table->unique(['project_id', 'competency_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_competencies');
    }
};
