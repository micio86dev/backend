<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create `bars_indicators` table (C3 Framework Catalog).
 *
 * Per-role, per-competency BARS indicators with reference anchors {5,3,1}.
 * All text fields are translatable JSON columns.
 * BARS JSON key→column mapping: indicator→text, scale.5→anchor_5, scale.3→anchor_3, scale.1→anchor_1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('framework_bars_indicators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained('framework_roles')->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained('framework_competencies')->cascadeOnDelete();
            $table->json('text');
            $table->json('anchor_5');
            $table->json('anchor_3');
            $table->json('anchor_1');
            $table->unsignedInteger('position')->default(0);
            $table->unique(['role_id', 'competency_id', 'position']);
            $table->index(['role_id', 'competency_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('framework_bars_indicators');
    }
};
