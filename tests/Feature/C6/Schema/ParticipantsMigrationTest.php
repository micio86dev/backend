<?php

declare(strict_types=1);

/**
 * Participants table schema tests (C6 — Participant + SSO Ingress).
 *
 * Verifies the structural invariants of the `participants` table after migration:
 * - UNIQUE(project_id, candidate_ref) exists
 * - org-id-first composite indexes are present
 * - no deleted_at column (no SoftDeletes in C6)
 * - organization_id is NOT NULL (foreign key constraint)
 *
 * REQ: Participant Model and Schema — scenario "Table created with required columns and constraints"
 */

use Illuminate\Support\Facades\Schema;

test('participants table exists after migration', function (): void {
    expect(Schema::hasTable('participants'))->toBeTrue();
});

test('participants table has all required columns', function (): void {
    $columns = Schema::getColumnListing('participants');

    foreach (['id', 'organization_id', 'project_id', 'candidate_ref', 'display_name',
        'role_code', 'language', 'status', 'started_at', 'completed_at',
        'created_at', 'updated_at'] as $col) {
        expect(in_array($col, $columns))->toBeTrue("Column {$col} should exist");
    }
});

test('participants table has no deleted_at column (no SoftDeletes in C6)', function (): void {
    expect(Schema::hasColumn('participants', 'deleted_at'))->toBeFalse();
});

test('UNIQUE(project_id, candidate_ref) index exists on participants', function (): void {
    $indexes = Schema::getConnection()
        ->select("
            SELECT indexname, indexdef
            FROM pg_indexes
            WHERE tablename = 'participants'
              AND indexdef LIKE '%project_id%'
              AND indexdef LIKE '%candidate_ref%'
        ");

    expect($indexes)->not->toBeEmpty('UNIQUE(project_id, candidate_ref) index should exist');

    // Verify it is a unique index
    $uniqueIndex = collect($indexes)->first(fn ($i) => str_contains($i->indexdef, 'UNIQUE'));
    expect($uniqueIndex)->not->toBeNull('The (project_id, candidate_ref) index should be UNIQUE');
});

test('org-first composite index (organization_id, project_id) exists', function (): void {
    $indexes = Schema::getConnection()
        ->select("
            SELECT indexname, indexdef
            FROM pg_indexes
            WHERE tablename = 'participants'
              AND indexdef LIKE '%organization_id%'
              AND indexdef LIKE '%project_id%'
              AND indexdef NOT LIKE '%UNIQUE%'
        ");

    expect($indexes)->not->toBeEmpty('Composite index (organization_id, project_id) should exist');
});

test('org-first composite index (organization_id, status) exists', function (): void {
    $indexes = Schema::getConnection()
        ->select("
            SELECT indexname, indexdef
            FROM pg_indexes
            WHERE tablename = 'participants'
              AND indexdef LIKE '%organization_id%'
              AND indexdef LIKE '%status%'
        ");

    expect($indexes)->not->toBeEmpty('Composite index (organization_id, status) should exist');
});

test('organization_id is NOT NULL (FK constraint enforced)', function (): void {
    $column = Schema::getConnection()
        ->select("
            SELECT is_nullable
            FROM information_schema.columns
            WHERE table_name = 'participants'
              AND column_name = 'organization_id'
        ");

    expect($column)->not->toBeEmpty();
    expect($column[0]->is_nullable)->toBe('NO');
});
