<?php

declare(strict_types=1);

/**
 * beai:reset-user-password — operator-initiated credential recovery for a
 * locked-out user, when no in-app recovery path exists.
 *
 * REQ: admin-password-reset (password-recovery capability) +
 * identity-auth delta (Out-of-Session Password Reset Invalidates Prior
 * Sessions).
 *
 * `Artisan::call()` + `Artisan::output()` is used for every test that
 * inspects printed bytes — `$this->artisan()` (PendingCommand) swallows the
 * output and the assertion would pass vacuously (documented precedent:
 * ProvisionOrganizationCommandTest.php).
 */

use App\Console\Commands\ResetUserPasswordCommand;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Deterministic double for the `generatePassword()` override seam — pins a
 * FIXED password containing every Symfony OutputFormatter-significant
 * character (`<info>`, bare `<`, `>`, `\`) instead of depending on a random
 * draw from `Str::password(20)`, which can and does produce them but not
 * reproducibly. NOT a shared fixture (tests/Helpers/): only this file uses
 * it, mirroring FixedPasswordProvisionOrganizationCommand.
 */
final class FixedPasswordResetUserPasswordCommand extends ResetUserPasswordCommand
{
    public const FIXED_PASSWORD = 'p<info>x</info>a<b>ss\\word>secret';

    protected function generatePassword(): string
    {
        return self::FIXED_PASSWORD;
    }
}

test('refuses an unknown email, writes nothing, and names the sibling bootstrap command', function (): void {
    $usersBefore = User::count();

    $exitCode = Artisan::call('beai:reset-user-password', [
        'email' => 'nobody@example.test',
    ]);

    expect($exitCode)->not->toBe(0);
    expect(User::count())->toBe($usersBefore);
    expect(Artisan::output())->toContain('beai:provision-organization');
});

test('resets a known user and prints a password that verifies against the stored hash', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    $exitCode = Artisan::call('beai:reset-user-password', [
        'email' => $user->email,
    ]);

    expect($exitCode)->toBe(0);

    $output = Artisan::output();
    preg_match('/New password:\s*(\S+)/', $output, $matches);
    $printed = $matches[1] ?? '';

    expect($printed)->not->toBe('');
    expect(Hash::check($printed, $user->fresh()->password))->toBeTrue();
});

test('a token minted before the reset is rejected on /auth/me and /auth/refresh afterward', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    $preResetToken = (string) auth('api')->login($user);

    $this->travel(2)->seconds();

    resetAuthGuardState();
    $exitCode = Artisan::call('beai:reset-user-password', [
        'email' => $user->email,
    ]);

    expect($exitCode)->toBe(0);

    resetAuthGuardState();
    $this->withToken($preResetToken)->getJson('/api/auth/me')
        ->assertStatus(401)
        ->assertJson(['error' => 'credentials_changed']);

    resetAuthGuardState();
    $this->withToken($preResetToken)->postJson('/api/auth/refresh')
        ->assertStatus(401);
});

test('a generated password containing OutputFormatter-significant characters reaches the operator byte-exact', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    app()->instance(ResetUserPasswordCommand::class, new FixedPasswordResetUserPasswordCommand);

    $exitCode = Artisan::call('beai:reset-user-password', [
        'email' => $user->email,
    ]);

    expect($exitCode)->toBe(0);

    $output = Artisan::output();

    // Byte-exact: `$this->line()` would let Symfony's OutputFormatter
    // interpret `<info>`/`<b>` as tags (stripped or re-styled) and `\` as an
    // escape character, so the printed string would differ from what was
    // actually hashed. This is the FORCE that proves it — not a comparison of
    // the fixture to itself: both the printed bytes AND the stored hash are
    // checked against the SAME fixture constant.
    expect($output)->toContain('New password: '.FixedPasswordResetUserPasswordCommand::FIXED_PASSWORD);
    expect(Hash::check(FixedPasswordResetUserPasswordCommand::FIXED_PASSWORD, $user->fresh()->password))->toBeTrue();
});

test('refuses a deactivated user without reactivating them or changing the password', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create([
        'organization_id' => $org->id,
        'deactivated_at' => now(),
    ]);
    $originalHash = $user->password;

    $exitCode = Artisan::call('beai:reset-user-password', [
        'email' => $user->email,
    ]);

    expect($exitCode)->not->toBe(0);

    $fresh = $user->fresh();
    expect($fresh->deactivated_at)->not->toBeNull();
    expect($fresh->password)->toBe($originalHash);
    expect(Artisan::output())->not->toContain('New password:');
});

test('a failure after the write rolls back the password and prints nothing', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    $originalHash = $user->password;

    // Registered on the per-test dispatcher, not User::saved(), so it does
    // not leak across the suite. The UPDATE runs first, then this throws —
    // proving the write only survives inside DB::transaction().
    Event::listen('eloquent.saved: '.User::class, function (): void {
        throw new RuntimeException('boom');
    });

    $exitCode = Artisan::call('beai:reset-user-password', [
        'email' => $user->email,
    ]);

    expect($exitCode)->not->toBe(0);
    // Fails without DB::transaction(): the UPDATE the listener observed
    // would otherwise have committed already.
    expect($user->fresh()->password)->toBe($originalHash);
    // Fails if printing moved inside the transaction closure: a credential
    // for a write that then rolled back must never reach the operator.
    expect(Artisan::output())->not->toContain('New password:');
});

/*
|--------------------------------------------------------------------------
| identity-auth delta: the second-precision comparison window
|--------------------------------------------------------------------------
|
| REQ: identity-auth delta, "The second-precision comparison window is
| honored". RejectStaleCredentials itself is pre-existing and unmodified by
| this change, so these two tests exercise the boundary this command's own
| write now participates in — closing a spec-asserted scenario that had zero
| covering test anywhere in the repo (AdminPasswordResetRevocationTest.php
| always travels 2 seconds forward and never lands on the boundary).
|
| `freezeSecond()` (not `freezeTime()`) pins the clock to the START of the
| current second, so a token minted immediately afterward gets an `iat`
| numerically equal to `password_changed_at` (also stored via
| `startOfSecond()`) — the exact equality case the strict `<` in
| RejectStaleCredentials must NOT reject. `travel(1)->second()` then moves
| only the reset's write into the NEXT second, without touching the token's
| already-fixed `iat`, producing the exact `iat < password_changed_at` case
| that MUST reject. Both are frozen/travelled Carbon time, not wall-clock
| sleeps — deterministic regardless of how fast this test executes.
*/

test('a token minted in the SAME wall-clock second as the reset survives (iat == password_changed_at, not <)', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->freezeSecond();
    $token = (string) auth('api')->login($user);

    resetAuthGuardState();
    $exitCode = Artisan::call('beai:reset-user-password', [
        'email' => $user->email,
    ]);

    expect($exitCode)->toBe(0);

    resetAuthGuardState();
    $this->withToken($token)->getJson('/api/auth/me')->assertOk();
});

test('a token minted one wall-clock second BEFORE the reset is rejected (iat < password_changed_at)', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->freezeSecond();
    $token = (string) auth('api')->login($user);

    $this->travel(1)->second();

    resetAuthGuardState();
    $exitCode = Artisan::call('beai:reset-user-password', [
        'email' => $user->email,
    ]);

    expect($exitCode)->toBe(0);

    resetAuthGuardState();
    $this->withToken($token)->getJson('/api/auth/me')
        ->assertStatus(401)
        ->assertJson(['error' => 'credentials_changed']);
});

/*
|--------------------------------------------------------------------------
| Characterization locks (Phase 3)
|--------------------------------------------------------------------------
|
| Written LAST, deliberately: these three are green the moment the command
| exists in the shape built above — non-interactivity falls out of NOT
| implementing PromptsForMissingInput, the missing --password option falls
| out of never having added one, and the output shape was completed as part
| of D5 in the same command file. Sequencing them as RED-first would be TDD
| theatre: there was never a version of this command where they failed.
*/

test('is not an implementation of PromptsForMissingInput, so a missing argument fails loudly instead of prompting', function (): void {
    // Command.php mixes in the PromptsForMissingInput TRAIT unconditionally,
    // but Concerns/PromptsForMissingInput.php:28 gates actual prompting on
    // the command implementing this CONTRACT. This is the single assertion
    // that keeps a missing argument from becoming a blocking ask() under a
    // headless shell — stronger than a --no-interaction smoke run, because
    // it proves the mechanism rather than one instance of it working.
    expect(new ResetUserPasswordCommand)
        ->toBeInstanceOf(Command::class)
        ->not->toBeInstanceOf(PromptsForMissingInput::class);
});

test('runs to completion under --no-interaction with no prompt', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    $exitCode = Artisan::call('beai:reset-user-password', [
        'email' => $user->email,
        '--no-interaction' => true,
    ]);

    expect($exitCode)->toBe(0);
    expect(Hash::check(
        (string) Str::of(Artisan::output())->after('New password: ')->before("\n"),
        $user->fresh()->password,
    ))->toBeTrue();
});

test('has no --password-equivalent option', function (): void {
    $command = app(Kernel::class)->all()['beai:reset-user-password'];

    expect($command->getDefinition()->hasOption('password'))->toBeFalse();
});

test('output shape: identity line first, then the password, then the two warn consequence lines', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    $exitCode = Artisan::call('beai:reset-user-password', [
        'email' => $user->email,
    ]);

    expect($exitCode)->toBe(0);

    $output = Artisan::output();
    $identityLinePos = strpos($output, "Password reset for: {$user->email}");
    $passwordLinePos = strpos($output, 'New password:');
    $revokedLinePos = strpos($output, 'All existing sessions for this user were revoked');
    $shownOnceLinePos = strpos($output, 'Shown once and not recoverable');

    expect($identityLinePos)->not->toBeFalse();
    expect($passwordLinePos)->not->toBeFalse();
    expect($revokedLinePos)->not->toBeFalse();
    expect($shownOnceLinePos)->not->toBeFalse();

    expect($identityLinePos)->toBeLessThan($passwordLinePos);
    expect($passwordLinePos)->toBeLessThan($revokedLinePos);
    expect($revokedLinePos)->toBeLessThan($shownOnceLinePos);
});

test('labels a platform superadmin (no organization) instead of printing a raw null', function (): void {
    // organization_id is deliberately left unset — UserFactory defaults it to
    // NULL, the same shape as a real platform superadmin.
    $user = User::factory()->create();
    expect($user->organization_id)->toBeNull();

    $exitCode = Artisan::call('beai:reset-user-password', [
        'email' => $user->email,
    ]);

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('org id=none (platform superadmin)');
});
