<?php

/**
 * RED — 4.1: Global catalog tables carry no organization_id (C3).
 *
 * Asserts that framework_roles, framework_competencies, and framework_bars_indicators
 * are GLOBAL tables: none of them has an organization_id column.
 *
 * NOTE: Tables are prefixed `framework_` to avoid collision with spatie/laravel-permission
 * which also creates a `roles` table. This is a necessary adaptation flagged in apply-progress.
 *
 * Refs spec: "Global tables carry no organization_id".
 */

use Illuminate\Support\Facades\Schema;

test('framework_roles table has no organization_id column', function (): void {
    expect(Schema::hasColumn('framework_roles', 'organization_id'))->toBeFalse();
});

test('framework_competencies table has no organization_id column', function (): void {
    expect(Schema::hasColumn('framework_competencies', 'organization_id'))->toBeFalse();
});

test('framework_bars_indicators table has no organization_id column', function (): void {
    expect(Schema::hasColumn('framework_bars_indicators', 'organization_id'))->toBeFalse();
});

test('framework_roles table has required columns', function (): void {
    expect(Schema::hasColumns('framework_roles', ['id', 'code', 'name', 'responsibilities', 'created_at', 'updated_at']))->toBeTrue();
});

test('framework_competencies table has required columns', function (): void {
    expect(Schema::hasColumns('framework_competencies', ['id', 'code', 'name', 'definition', 'type', 'created_at', 'updated_at']))->toBeTrue();
});

test('framework_bars_indicators table has required columns', function (): void {
    expect(Schema::hasColumns('framework_bars_indicators', ['id', 'role_id', 'competency_id', 'text', 'anchor_5', 'anchor_3', 'anchor_1', 'position', 'created_at', 'updated_at']))->toBeTrue();
});
