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
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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

/*
|--------------------------------------------------------------------------
| Audit trail (security-review gap closure)
|--------------------------------------------------------------------------
|
| The mechanism already existed — `audit_logs` + `AuditRecorder` (C13,
| tests/Feature/C13/AuditLogTest.php) — and this reuses it rather than
| inventing a second one. `AuditLog::withoutGlobalScopes()` is required
| because the tenant global scope filters by the CURRENT resolver orgId,
| which an Artisan console call does not set the way an authenticated HTTP
| request does.
*/

test('a successful reset writes an audit record with the target user, email, organization and operator', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    $exitCode = Artisan::call('beai:reset-user-password', [
        'email' => $user->email,
        '--operator' => 'jane@ops.example',
    ]);

    expect($exitCode)->toBe(0);

    $row = AuditLog::withoutGlobalScopes()->where('action', 'user.password_reset')->first();

    expect($row)->not->toBeNull();
    expect($row->subject_type)->toBe('user');
    expect($row->subject_id)->toBe($user->id);
    expect($row->organization_id)->toBe($org->id);
    expect($row->after['email'])->toBe($user->email);
    expect($row->after['operator'])->toBe('jane@ops.example');
    expect($row->created_at)->not->toBeNull();
});

test('the audit record does not contain the generated password', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    app()->instance(ResetUserPasswordCommand::class, new FixedPasswordResetUserPasswordCommand);

    $exitCode = Artisan::call('beai:reset-user-password', [
        'email' => $user->email,
    ]);

    expect($exitCode)->toBe(0);

    $row = AuditLog::withoutGlobalScopes()->where('action', 'user.password_reset')->firstOrFail();

    // Asserted against the ACTUAL generated value, not a placeholder string —
    // a test written against a fake value would still pass even if the real
    // password leaked into the row.
    //
    // Walked recursively rather than compared against json_encode() output.
    // The first version of this test did the latter and COULD NOT FAIL: the
    // fixed password contains `</info>`, json_encode escapes the slash to
    // `<\/info>`, and the escaped form never matches the unescaped constant.
    // Mutating the command to write the plaintext straight into the audit
    // payload left it green. A test that cannot fail on the thing it covers
    // is worse than no test, because it is counted as coverage.
    $flatten = function (mixed $value) use (&$flatten): array {
        if (is_array($value)) {
            return array_merge(...array_map($flatten, array_values($value)) ?: [[]]);
        }

        return $value === null ? [] : [(string) $value];
    };

    $leaves = array_merge($flatten($row->before), $flatten($row->after), [
        (string) $row->action,
        (string) $row->subject_type,
    ]);

    foreach ($leaves as $leaf) {
        expect($leaf)->not->toContain(FixedPasswordResetUserPasswordCommand::FIXED_PASSWORD);
    }
});

test('an unknown email writes no audit record', function (): void {
    Artisan::call('beai:reset-user-password', ['email' => 'nobody@example.test']);

    expect(AuditLog::withoutGlobalScopes()->where('action', 'user.password_reset')->exists())->toBeFalse();
});

test('a deactivated user writes no audit record', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create([
        'organization_id' => $org->id,
        'deactivated_at' => now(),
    ]);

    Artisan::call('beai:reset-user-password', ['email' => $user->email]);

    expect(AuditLog::withoutGlobalScopes()->where('action', 'user.password_reset')->exists())->toBeFalse();
});

test('a failure after the write rolls back and leaves no audit record', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    // KNOWN CEILING (same shape as the pre-existing rollback test above for
    // report()): this listener throws SYNCHRONOUSLY during save(), inside
    // DB::transaction()'s closure, so it cannot distinguish "audit written
    // after commit" from "audit written right after save(), still inside
    // the closure" — nothing placed after save() in that closure runs
    // either way. See the comment above recordAudit() in
    // ResetUserPasswordCommand.php for the full explanation.
    Event::listen('eloquent.saved: '.User::class, function (): void {
        throw new RuntimeException('boom');
    });

    Artisan::call('beai:reset-user-password', ['email' => $user->email]);

    expect(AuditLog::withoutGlobalScopes()->where('action', 'user.password_reset')->exists())->toBeFalse();
});

test('a platform superadmin reset cannot persist to the tenant-scoped audit table and falls back to the application log', function (): void {
    // audit_logs.organization_id is a NOT NULL foreign key — a platform
    // superadmin (organization_id IS NULL) cannot be represented as a row in
    // it without either faking an org (misattributing the event to a real
    // tenant) or widening the column (weakening the isolation invariant the
    // table exists to enforce). This is the documented fallback, not a gap.
    Log::spy();

    $user = User::factory()->create();
    expect($user->organization_id)->toBeNull();

    $exitCode = Artisan::call('beai:reset-user-password', [
        'email' => $user->email,
        '--operator' => 'root',
    ]);

    expect($exitCode)->toBe(0);
    expect(AuditLog::withoutGlobalScopes()->where('action', 'user.password_reset')->exists())->toBeFalse();

    Log::shouldHaveReceived('notice')->once()->withArgs(function (string $message, array $context) use ($user): bool {
        return $message === 'audit.user.password_reset'
            && $context['user_id'] === $user->id
            && $context['email'] === $user->email
            && $context['operator'] === 'root'
            && $context['organization_id'] === null;
    });
});
