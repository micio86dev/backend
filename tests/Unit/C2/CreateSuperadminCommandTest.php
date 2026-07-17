<?php

/**
 * CreateSuperadmin artisan command unit tests (C2).
 *
 * Asserts:
 * - command creates a user with organization_id=null and is_superadmin=true
 * - validation fails when any required field is blank (exit code 1, no DB write)
 * - command is NOT registered via automated seeders (no seeder calls it)
 *
 * These tests write to the real test DB (no RefreshDatabase). Each test cleans up
 * its own rows in afterEach so the suite is idempotent across repeated runs.
 */

use App\Models\User;

// Clean up superadmin test rows before each test so repeated runs are idempotent.
beforeEach(function (): void {
    User::whereIn('email', [
        'admin@example.com',
        'admin-name-missing@example.com',
        'admin-pass-missing@example.com',
    ])->delete();
});

test('app:create-superadmin command creates user with null org and is_superadmin true', function (): void {
    $this->artisan('app:create-superadmin')
        ->expectsQuestion('Email', 'admin@example.com')
        ->expectsQuestion('Name', 'Platform Admin')
        ->expectsQuestion('Password', 'secret-password-123')
        ->assertExitCode(0);

    $user = User::where('email', 'admin@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->organization_id)->toBeNull();
    expect($user->is_superadmin)->toBeTrue();
});

test('app:create-superadmin fails when email is empty', function (): void {
    // Validates the empty-field guard (lines 47-50): any blank required arg → FAILURE exit.
    // Email is blank — the command must output the error and return FAILURE without inserting a row.
    $countBefore = User::where('is_superadmin', true)->count();

    $this->artisan('app:create-superadmin')
        ->expectsQuestion('Email', '')
        ->expectsQuestion('Name', 'Superadmin No Email')
        ->expectsQuestion('Password', 'secret-password-123')
        ->expectsOutput('Email, name, and password are all required.')
        ->assertExitCode(1);

    // No new superadmin must have been inserted.
    expect(User::where('is_superadmin', true)->count())->toBe($countBefore);
});

test('app:create-superadmin fails when name is empty', function (): void {
    $this->artisan('app:create-superadmin')
        ->expectsQuestion('Email', 'admin-name-missing@example.com')
        ->expectsQuestion('Name', '')
        ->expectsQuestion('Password', 'secret-password-123')
        ->expectsOutput('Email, name, and password are all required.')
        ->assertExitCode(1);

    expect(User::where('email', 'admin-name-missing@example.com')->count())->toBe(0);
});

test('app:create-superadmin fails when password is empty', function (): void {
    $this->artisan('app:create-superadmin')
        ->expectsQuestion('Email', 'admin-pass-missing@example.com')
        ->expectsQuestion('Name', 'Platform Admin')
        ->expectsQuestion('Password', '')
        ->expectsOutput('Email, name, and password are all required.')
        ->assertExitCode(1);

    expect(User::where('email', 'admin-pass-missing@example.com')->count())->toBe(0);
});

test('app:create-superadmin is not called from DatabaseSeeder', function (): void {
    // Structural check: the DatabaseSeeder must not call artisan('app:create-superadmin').
    // This prevents automated infrastructure (CI, staging reset) from minting a superadmin.
    $seederPath = base_path('database/seeders/DatabaseSeeder.php');

    if (! file_exists($seederPath)) {
        // No DatabaseSeeder yet — invariant trivially holds.
        expect(true)->toBeTrue();

        return;
    }

    $seederContent = file_get_contents($seederPath);
    expect($seederContent)->not->toContain('app:create-superadmin');
    expect($seederContent)->not->toContain('CreateSuperadmin');
});
