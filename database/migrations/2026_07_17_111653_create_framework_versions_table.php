<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create tenant-scoped `framework_versions` table (C3 Framework Catalog).
 *
 * Extends C2 TenantModel pattern — organization_id scoped.
 * In C3 this is a WORKING DRAFT label; C4 sets is_locked=true on pin (snapshot-at-pin).
 * composite unique index leads with organization_id per D22 multi-tenancy convention.
 * label: human display name for the draft version (nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('framework_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('version');
            $table->boolean('is_locked')->default(false);
            $table->string('label')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('framework_versions');
    }
};
