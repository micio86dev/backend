<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('users table has organization_id nullable FK column with index', function (): void {
    expect(Schema::hasColumn('users', 'organization_id'))->toBeTrue();

    // Verify organization_id is nullable (can be NULL)
    $columns = DB::select("
        SELECT column_name, is_nullable, data_type
        FROM information_schema.columns
        WHERE table_name = 'users'
          AND table_schema = current_schema()
          AND column_name = 'organization_id'
    ");

    expect($columns)->toHaveCount(1);
    expect($columns[0]->is_nullable)->toBe('YES');
    expect($columns[0]->data_type)->toBe('bigint');
});

it('users.organization_id has a FK referencing organizations with restrictOnDelete', function (): void {
    // Verify FK constraint with RESTRICT delete rule
    $constraints = DB::select("
        SELECT
            tc.constraint_name,
            rc.delete_rule
        FROM information_schema.table_constraints tc
        JOIN information_schema.referential_constraints rc
            ON tc.constraint_name = rc.constraint_name
        JOIN information_schema.key_column_usage kcu
            ON kcu.constraint_name = tc.constraint_name
        WHERE tc.table_name = 'users'
          AND kcu.column_name = 'organization_id'
          AND tc.constraint_type = 'FOREIGN KEY'
    ");

    expect($constraints)->not->toBeEmpty('FK on users.organization_id not found');
    expect($constraints[0]->delete_rule)->toBe('RESTRICT');
});

it('users table has is_superadmin boolean NOT NULL default false', function (): void {
    expect(Schema::hasColumn('users', 'is_superadmin'))->toBeTrue();

    $columns = DB::select("
        SELECT column_name, is_nullable, column_default, data_type
        FROM information_schema.columns
        WHERE table_name = 'users'
          AND table_schema = current_schema()
          AND column_name = 'is_superadmin'
    ");

    expect($columns)->toHaveCount(1);
    expect($columns[0]->is_nullable)->toBe('NO');
    expect($columns[0]->data_type)->toBe('boolean');
    expect($columns[0]->column_default)->toContain('false');
});

it('users.organization_id has a dedicated index', function (): void {
    $indexes = DB::select("
        SELECT indexname
        FROM pg_indexes
        WHERE tablename = 'users'
          AND indexdef LIKE '%organization_id%'
    ");

    expect($indexes)->not->toBeEmpty('Index on users.organization_id not found');
});

it('migrate rollback and re-migrate leaves schema identical', function (): void {
    // Verify rollback removes the columns cleanly
    Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);

    expect(Schema::hasColumn('users', 'organization_id'))->toBeFalse();
    expect(Schema::hasColumn('users', 'is_superadmin'))->toBeFalse();

    // Re-apply migration
    Artisan::call('migrate', ['--force' => true]);

    expect(Schema::hasColumn('users', 'organization_id'))->toBeTrue();
    expect(Schema::hasColumn('users', 'is_superadmin'))->toBeTrue();
});
