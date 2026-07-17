<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use App\Models\TenantModel;

/**
 * Minimal TenantScoped model used exclusively in isolation tests.
 *
 * Backed by the `sample_tenant_records` table created by the
 * CreateSampleTenantRecordsTable migration in
 * database/migrations/test-only/ (only loaded during test runs).
 *
 * This fixture proves the TenantScoped trait works end-to-end without
 * coupling the test to a future production model.
 */
class SampleTenantRecord extends TenantModel
{
    protected $table = 'sample_tenant_records';

    /**
     * @var list<string>
     */
    protected $fillable = ['title'];
}
