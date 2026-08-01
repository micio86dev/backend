<?php

declare(strict_types=1);

/**
 * RED — 1.2: project_competencies pivot schema (C4).
 *
 * Asserts id, project_id (cascadeOnDelete), competency_id (restrictOnDelete),
 * position, and unique(project_id, competency_id). No timestamps.
 * Refs spec: Org-Scoped Project Entity.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('project_competencies table exists', function (): void {
    expect(Schema::hasTable('project_competencies'))->toBeTrue();
});

test('project_competencies has required columns', function (): void {
    foreach (['id', 'project_id', 'competency_id', 'position'] as $col) {
        expect(Schema::hasColumn('project_competencies', $col))->toBeTrue(
            "Expected project_competencies to have column '{$col}'"
        );
    }
});

test('project_competencies has no timestamps', function (): void {
    expect(Schema::hasColumn('project_competencies', 'created_at'))->toBeFalse();
    expect(Schema::hasColumn('project_competencies', 'updated_at'))->toBeFalse();
});

test('project_competencies has unique constraint on (project_id, competency_id)', function (): void {
    $indexes = DB::select(
        "SELECT indexname, indexdef
         FROM pg_indexes
         WHERE tablename = 'project_competencies'
         AND indexdef LIKE '%project_id%'
         AND indexdef LIKE '%competency_id%'"
    );

    $hasUnique = collect($indexes)->contains(fn ($idx) => str_contains(strtolower($idx->indexdef), 'unique'));
    expect($hasUnique)->toBeTrue('Expected unique index on (project_id, competency_id)');
});

test('project_competencies FK project_id cascades on delete', function (): void {
    $constraints = DB::select(
        "SELECT kcu.column_name, rc.delete_rule
         FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu
           ON tc.constraint_name = kcu.constraint_name
         JOIN information_schema.referential_constraints rc
           ON tc.constraint_name = rc.constraint_name
         WHERE tc.table_name = 'project_competencies'
           AND tc.constraint_type = 'FOREIGN KEY'
           AND kcu.column_name = 'project_id'
           AND rc.delete_rule = 'CASCADE'"
    );

    expect($constraints)->not->toBeEmpty('Expected FK on project_id with CASCADE on delete');
});

test('project_competencies FK competency_id restricts on delete', function (): void {
    $constraints = DB::select(
        "SELECT kcu.column_name, rc.delete_rule
         FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu
           ON tc.constraint_name = kcu.constraint_name
         JOIN information_schema.referential_constraints rc
           ON tc.constraint_name = rc.constraint_name
         WHERE tc.table_name = 'project_competencies'
           AND tc.constraint_type = 'FOREIGN KEY'
           AND kcu.column_name = 'competency_id'
           AND rc.delete_rule = 'RESTRICT'"
    );

    expect($constraints)->not->toBeEmpty('Expected FK on competency_id with RESTRICT on delete');
});
