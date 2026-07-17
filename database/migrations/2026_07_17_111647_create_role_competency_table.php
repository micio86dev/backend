<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create `role_competency` pivot table (C3 Framework Catalog).
 *
 * Normalized pivot — enables per-role BARS indicators with FK integrity.
 * position: stable ordered list of competencies per role.
 * unique(role_id, competency_id): each competency assigned to a role exactly once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('framework_role_competency', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained('framework_roles')->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained('framework_competencies')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->unique(['role_id', 'competency_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('framework_role_competency');
    }
};
