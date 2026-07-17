<?php

/**
 * CreateSuperadmin artisan command unit tests (C2).
 *
 * Asserts:
 * - command creates a user with organization_id=null and is_superadmin=true
 * - command is NOT registered via automated seeders (no seeder calls it)
 */

use App\Models\User;

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
