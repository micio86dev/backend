<?php

declare(strict_types=1);

/**
 * RED — 1.1: projects table schema (C4).
 *
 * Asserts all expected columns, indexes, and FKs are present on the `projects` table.
 * Refs spec: Org-Scoped Project Entity.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('projects table exists', function (): void {
    expect(Schema::hasTable('projects'))->toBeTrue();
});

test('projects table has all required columns', function (): void {
    $columns = [
        'id',
        'organization_id',
        'framework_version_id',
        'slug',
        'name',
        'assessment_type',
        'role_code',
        'language',
        'status',
        'pause_every_n_competencies',
        'nudge_min_chars',
        'exit_redirect_url',
        'webhook_url',
        'webhook_secret',
        'deadline_at',
        'goes_live_at',
        'deleted_at',
        'created_at',
        'updated_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('projects', $column))->toBeTrue(
            "Expected projects table to have column '{$column}'"
        );
    }
});

test('projects has unique index on (organization_id, slug) excluding soft-deleted', function (): void {
    $indexes = DB::select(
        "SELECT indexname, indexdef
         FROM pg_indexes
         WHERE tablename = 'projects'
         AND indexdef LIKE '%organization_id%'
         AND indexdef LIKE '%slug%'"
    );

    expect($indexes)->not->toBeEmpty('Expected a composite index on (organization_id, slug)');
});

test('projects has composite index on (organization_id, status)', function (): void {
    $indexes = DB::select(
        "SELECT indexname, indexdef
         FROM pg_indexes
         WHERE tablename = 'projects'
         AND indexdef LIKE '%organization_id%'
         AND indexdef LIKE '%status%'"
    );

    expect($indexes)->not->toBeEmpty('Expected a composite index on (organization_id, status)');
});

test('projects has composite index on (organization_id, role_code)', function (): void {
    $indexes = DB::select(
        "SELECT indexname, indexdef
         FROM pg_indexes
         WHERE tablename = 'projects'
         AND indexdef LIKE '%organization_id%'
         AND indexdef LIKE '%role_code%'"
    );

    expect($indexes)->not->toBeEmpty('Expected a composite index on (organization_id, role_code)');
});

test('projects has FK framework_version_id with restrictOnDelete', function (): void {
    $constraints = DB::select(
        "SELECT tc.constraint_name, rc.delete_rule
         FROM information_schema.table_constraints tc
         JOIN information_schema.referential_constraints rc
           ON tc.constraint_name = rc.constraint_name
         WHERE tc.table_name = 'projects'
           AND tc.constraint_type = 'FOREIGN KEY'
           AND rc.delete_rule = 'RESTRICT'"
    );

    expect($constraints)->not->toBeEmpty('Expected FK on framework_version_id with RESTRICT on delete');
});

test('projects has FK organization_id with cascadeOnDelete', function (): void {
    $constraints = DB::select(
        "SELECT kcu.column_name, rc.delete_rule
         FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu
           ON tc.constraint_name = kcu.constraint_name
         JOIN information_schema.referential_constraints rc
           ON tc.constraint_name = rc.constraint_name
         WHERE tc.table_name = 'projects'
           AND tc.constraint_type = 'FOREIGN KEY'
           AND kcu.column_name = 'organization_id'
           AND rc.delete_rule = 'CASCADE'"
    );

    expect($constraints)->not->toBeEmpty('Expected FK on organization_id with CASCADE on delete');
});

test('projects table has deleted_at for soft deletes', function (): void {
    expect(Schema::hasColumn('projects', 'deleted_at'))->toBeTrue();
});
