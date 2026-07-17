<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only migration: creates the `sample_tenant_records` table used by
 * Tests\Fixtures\Models\SampleTenantRecord in C2 isolation tests.
 *
 * This migration is loaded exclusively during test runs (see phpunit.xml
 * DB_MIGRATIONS_PATHS override). It MUST NOT be loaded in production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sample_tenant_records', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->foreignId('organization_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->timestamps();

            // Composite index leading with organization_id (D22 compliance)
            $table->index(['organization_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_tenant_records');
    }
};
