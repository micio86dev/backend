<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create global `competencies` table (C3 Framework Catalog).
 *
 * GLOBAL — no organization_id. Competencies are shared across all tenants.
 * type enum: 'standard' (18 competencies) | 'potential' (MTG/LAT — authored later).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('framework_competencies', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->json('name');
            $table->json('definition');
            $table->string('type')->default('standard'); // enum: standard|potential
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('framework_competencies');
    }
};
