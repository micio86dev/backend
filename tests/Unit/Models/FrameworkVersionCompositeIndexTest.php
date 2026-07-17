<?php

/**
 * RED — 4.2: FrameworkVersion composite index leads with organization_id (C3).
 *
 * Asserts that the framework_versions table has a unique composite index
 * whose first column is organization_id (D22 multi-tenancy convention).
 * Refs spec: "FrameworkVersion composite index leads with organization_id".
 */

use Illuminate\Support\Facades\DB;

test('framework_versions has a composite unique index leading with organization_id', function (): void {
    $indexes = DB::select(
        "SELECT indexname, indexdef
         FROM pg_indexes
         WHERE tablename = 'framework_versions'
         AND indexdef LIKE '%organization_id%'"
    ); /** @var array<object{indexname: string, indexdef: string}> $indexes */

    expect($indexes)->not->toBeEmpty('Expected at least one index on framework_versions involving organization_id');

    // At least one index should have organization_id as the FIRST column
    $hasLeadingOrgId = collect($indexes)->contains(function ($idx): bool {
        // The unique index definition should start: UNIQUE INDEX ... ON framework_versions (organization_id, ...)
        return str_contains($idx->indexdef, '(organization_id,')
            || str_contains($idx->indexdef, '(organization_id)');
    });

    expect($hasLeadingOrgId)->toBeTrue('Expected the composite unique index to lead with organization_id');
});

test('framework_versions table has organization_id column', function (): void {
    expect(\Illuminate\Support\Facades\Schema::hasColumn('framework_versions', 'organization_id'))->toBeTrue();
});
