<?php

declare(strict_types=1);

/**
 * beai:provision-organization — the bootstrap that makes a fresh deployment
 * usable.
 *
 * The command exists because a migrated database with no organization has no
 * admin, and therefore no account anyone can log in with. What is asserted here
 * is mostly about the two ways that bootstrap fails SILENTLY:
 *
 *   - a role written with team_id = NULL, which is invisible to every
 *     teams-mode hasRole() check — present in the database, inert in practice
 *   - a half-provisioned tenant left behind by a failed run, which has to be
 *     cleaned up by hand in production
 *
 * Both look like success from the outside, which is why they are tested from
 * the inside.
 */

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('provisions the organization, its roles and its admin', function (): void {
    $this->artisan('beai:provision-organization', [
        '--name' => 'Acme Corp',
        '--admin-email' => 'admin@acme.test',
        '--admin-name' => 'Acme Admin',
    ])->assertExitCode(0);

    $org = Organization::where('slug', 'acme-corp')->first();
    expect($org)->not->toBeNull();
    expect($org->name)->toBe('Acme Corp');

    $user = User::where('email', 'admin@acme.test')->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Acme Admin');
    expect($user->organization_id)->toBe($org->id);
});

test('the admin is an organization admin, never a platform superadmin', function (): void {
    // Two different identities: is_superadmin bypasses tenancy entirely, an org
    // admin does not. C2 forbids conflating them, and this command mints the
    // privileged account — so the distinction is asserted, not assumed.
    $this->artisan('beai:provision-organization', [
        '--name' => 'Acme Corp',
        '--admin-email' => 'admin@acme.test',
    ])->assertExitCode(0);

    $user = User::where('email', 'admin@acme.test')->first();

    expect($user->is_superadmin)->toBeFalse();
    expect($user->organization_id)->not->toBeNull();
});

test('creates all three roles scoped to the organization', function (): void {
    $this->artisan('beai:provision-organization', [
        '--name' => 'Acme Corp',
        '--admin-email' => 'admin@acme.test',
    ])->assertExitCode(0);

    $org = Organization::where('slug', 'acme-corp')->firstOrFail();

    foreach (['admin', 'operator', 'viewer'] as $roleName) {
        $role = Role::where('name', $roleName)
            ->where('guard_name', 'api')
            ->where('team_id', $org->id)
            ->first();

        // A role written with team_id = NULL is invisible to every teams-mode
        // check. It has shipped that way once already in RolesAndPermissionsSeeder:
        // seeded, present in the table, and silently inert.
        expect($role)->not->toBeNull("role [{$roleName}] must exist scoped to the org");
    }

    expect(Role::where('team_id', $org->id)->whereNull('team_id')->count())->toBe(0);
});

test('the admin actually holds the admin role in the organization context', function (): void {
    $this->artisan('beai:provision-organization', [
        '--name' => 'Acme Corp',
        '--admin-email' => 'admin@acme.test',
    ])->assertExitCode(0);

    $org = Organization::where('slug', 'acme-corp')->firstOrFail();
    $user = User::where('email', 'admin@acme.test')->firstOrFail();

    // The team context has to be set before asking, exactly as the application
    // does at request time. Without it the check returns false for a role that
    // is correctly assigned — which is why this is the assertion that matters
    // more than the row existing.
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);

    expect($user->fresh()->hasRole('admin'))->toBeTrue();
});

test('derives the slug from the name but honours an explicit one', function (): void {
    $this->artisan('beai:provision-organization', [
        '--name' => 'Acme Corp',
        '--slug' => 'acme-emea',
        '--admin-email' => 'admin@acme.test',
    ])->assertExitCode(0);

    expect(Organization::where('slug', 'acme-emea')->exists())->toBeTrue();
    expect(Organization::where('slug', 'acme-corp')->exists())->toBeFalse();
});

test('generates a password and shows it exactly once', function (): void {
    // Artisan::call(), not $this->artisan(): the PendingCommand helper runs the
    // command against its own output expectations, so Artisan::output() would
    // come back empty and every assertion below would pass vacuously.
    $exitCode = Artisan::call('beai:provision-organization', [
        '--name' => 'Acme Corp',
        '--admin-email' => 'admin@acme.test',
    ]);

    expect($exitCode)->toBe(0);

    $output = Artisan::output();

    // The operator has no other way to obtain it: it is generated here and
    // stored only as a hash. If it is not printed, the account is unusable.
    expect($output)->toContain('Password:');

    preg_match('/Password:\s*(\S+)/', $output, $matches);
    $password = $matches[1] ?? '';

    expect($password)->not->toBe('');
    expect(Hash::check($password, User::where('email', 'admin@acme.test')->firstOrFail()->password))->toBeTrue();
});

test('uses a supplied password and does not echo it', function (): void {
    $exitCode = Artisan::call('beai:provision-organization', [
        '--name' => 'Acme Corp',
        '--admin-email' => 'admin@acme.test',
        '--admin-password' => 'operator-chosen-secret-123',
    ]);

    expect($exitCode)->toBe(0);

    $user = User::where('email', 'admin@acme.test')->firstOrFail();
    expect(Hash::check('operator-chosen-secret-123', $user->password))->toBeTrue();

    // The operator already has it. Echoing only widens where it lands — shell
    // history, CI logs, a shared screen.
    expect(Artisan::output())->not->toContain('operator-chosen-secret-123');
});

test('refuses a duplicate slug and writes nothing', function (): void {
    Organization::create(['name' => 'Existing', 'slug' => 'acme-corp']);

    $usersBefore = User::count();

    $this->artisan('beai:provision-organization', [
        '--name' => 'Acme Corp',
        '--admin-email' => 'admin@acme.test',
    ])->assertExitCode(1);

    expect(User::count())->toBe($usersBefore);
    expect(Organization::where('slug', 'acme-corp')->count())->toBe(1);
});

test('rolls the organization back when the admin insert fails at the database', function (): void {
    // The ONLY test here that actually exercises the transaction. Every other
    // failure path is caught by validateInput() before a single row is written,
    // so they would pass just as happily with no transaction at all — verified
    // by removing DB::transaction() and watching all of them stay green.
    //
    // This one gets past validation and dies on the INSERT: users.name is
    // varchar(255) and nothing validates its length. If the write is not
    // wrapped, the organization row survives and the deployment is left with a
    // tenant that has no admin.
    $this->artisan('beai:provision-organization', [
        '--name' => 'Acme Corp',
        '--admin-email' => 'admin@acme.test',
        '--admin-name' => str_repeat('a', 300),
    ])->assertExitCode(1);

    expect(Organization::where('slug', 'acme-corp')->exists())->toBeFalse();
    expect(User::where('email', 'admin@acme.test')->exists())->toBeFalse();
});

test('refuses a duplicate admin email and leaves no orphan organization', function (): void {
    User::create([
        'name' => 'Someone',
        'email' => 'admin@acme.test',
        'password' => 'irrelevant-but-present',
    ]);

    $this->artisan('beai:provision-organization', [
        '--name' => 'Acme Corp',
        '--admin-email' => 'admin@acme.test',
    ])->assertExitCode(1);

    // The failure mode this guards: an organization written before the user
    // insert blew up, leaving a tenant with no admin — invisible from the
    // backoffice and removable only by hand, in production.
    expect(Organization::where('slug', 'acme-corp')->exists())->toBeFalse();
});

test('requires a name and an admin email', function (): void {
    $this->artisan('beai:provision-organization', [
        '--admin-email' => 'admin@acme.test',
    ])->assertExitCode(1);

    $this->artisan('beai:provision-organization', [
        '--name' => 'Acme Corp',
    ])->assertExitCode(1);

    expect(Organization::where('slug', 'acme-corp')->exists())->toBeFalse();
});

test('rejects a malformed admin email', function (): void {
    $this->artisan('beai:provision-organization', [
        '--name' => 'Acme Corp',
        '--admin-email' => 'not-an-email',
    ])->assertExitCode(1);

    expect(Organization::where('slug', 'acme-corp')->exists())->toBeFalse();
});

test('runs with no interaction at all', function (): void {
    // The whole point of the command. app:create-superadmin cannot do this:
    // its ask() calls return null under --no-interaction and it then rejects
    // them as blank, so it can never bootstrap a container.
    $this->artisan('beai:provision-organization', [
        '--name' => 'Acme Corp',
        '--admin-email' => 'admin@acme.test',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect(Organization::where('slug', 'acme-corp')->exists())->toBeTrue();
});
