<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create global `framework_roles` table (C3 Framework Catalog).
 *
 * GLOBAL — no organization_id. Roles are shared across all tenants.
 * Translatable columns (name, responsibilities) store JSON keyed by locale.
 *
 * NOTE: Named `framework_roles` (not `roles`) to avoid collision with the
 * `roles` table created by spatie/laravel-permission (installed in C2).
 * All other framework-catalog tables follow the `framework_` prefix convention
 * (framework_versions, framework_gaps, framework_bars_indicators, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('framework_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->json('name');
            $table->json('responsibilities');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('framework_roles');
    }
};
