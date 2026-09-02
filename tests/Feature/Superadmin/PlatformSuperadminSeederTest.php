<?php

declare(strict_types=1);

/**
 * Provisioning the platform superadmin where nobody can answer a prompt.
 *
 * `app:create-superadmin` asks three questions, which works on a laptop and
 * not in a deploy hook. This seeder is the same account from configuration.
 */

use App\Models\User;
use Database\Seeders\PlatformSuperadminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it creates a superadmin with no organization', function (): void {
    // NULL organization AND the flag: TenantContext grants bypass only for
    // both together, and either one alone fails closed with a 403.
    config()->set('superadmin.email', 'root@beai.test');
    config()->set('superadmin.password', 'a-real-password');

    (new PlatformSuperadminSeeder)->run();

    $user = User::withoutGlobalScopes()->where('email', 'root@beai.test')->first();

    expect($user)->not->toBeNull()
        ->and($user->is_superadmin)->toBeTrue()
        ->and($user->organization_id)->toBeNull()
        ->and($user->deactivated_at)->toBeNull();
});

test('it REFUSES to invent a password', function (): void {
    // A default credential on an account that crosses every tenant is the
    // first thing anyone would try. Failing the deploy is the correct outcome.
    config()->set('superadmin.email', 'root@beai.test');
    config()->set('superadmin.password', '');

    expect(fn () => (new PlatformSuperadminSeeder)->run())
        ->toThrow(RuntimeException::class);

    expect(User::withoutGlobalScopes()->where('email', 'root@beai.test')->exists())->toBeFalse();
});

test('it is opt-in: no email configured, nothing happens', function (): void {
    config()->set('superadmin.email', '');

    (new PlatformSuperadminSeeder)->run();

    expect(User::withoutGlobalScopes()->where('is_superadmin', true)->count())->toBe(0);
});

test('it NEVER resets an existing password', function (): void {
    // A seeder that rewrote the password on every deploy would silently undo
    // a rotation, and the operator would have no way to tell why the new one
    // stopped working.
    config()->set('superadmin.email', 'root@beai.test');
    config()->set('superadmin.password', 'first-password');
    (new PlatformSuperadminSeeder)->run();

    $before = User::withoutGlobalScopes()->where('email', 'root@beai.test')->firstOrFail()->password;

    config()->set('superadmin.password', 'a-different-password');
    (new PlatformSuperadminSeeder)->run();

    $after = User::withoutGlobalScopes()->where('email', 'root@beai.test')->firstOrFail()->password;

    expect($after)->toBe($before);
});

test('it REPAIRS an account that was demoted or predates the flag', function (): void {
    config()->set('superadmin.email', 'root@beai.test');
    config()->set('superadmin.password', 'a-real-password');
    (new PlatformSuperadminSeeder)->run();

    User::withoutGlobalScopes()->where('email', 'root@beai.test')
        ->update(['is_superadmin' => false, 'deactivated_at' => now()]);

    (new PlatformSuperadminSeeder)->run();

    $user = User::withoutGlobalScopes()->where('email', 'root@beai.test')->firstOrFail();

    expect($user->is_superadmin)->toBeTrue()
        ->and($user->deactivated_at)->toBeNull();
});
