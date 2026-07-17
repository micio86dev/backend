<?php

declare(strict_types=1);

namespace Tests\Fixtures\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trait for C2 isolation tests that use SampleTenantRecord.
 *
 * Creates the sample_tenant_records table inline (not via migration) so:
 * - It does not affect migrate:rollback ordering.
 * - It does not appear in the production migration stack.
 * - RefreshDatabase (which runs migrate:fresh) also creates it because
 *   we recreate it in setUp() after the fresh migrate.
 *
 * Usage: add `use CreatesSampleTenantTable;` to test files that need it.
 */
trait CreatesSampleTenantTable
{
    /**
     * Create the sample_tenant_records table for isolation tests.
     * Called after RefreshDatabase has run migrate:fresh.
     */
    protected function createSampleTenantTable(): void
    {
        if (! Schema::hasTable('sample_tenant_records')) {
            Schema::create('sample_tenant_records', function (Blueprint $table): void {
                $table->id();
                $table->string('title');
                $table->foreignId('organization_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete();
                $table->timestamps();
                $table->index(['organization_id', 'id']);
            });
        }
    }
}
