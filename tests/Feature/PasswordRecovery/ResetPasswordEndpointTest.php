<?php

declare(strict_types=1);

/**
 * `POST /api/auth/reset-password` — self-service password reset, confirm leg
 * (self-service-password-reset AD-2, AD-4, AD-7).
 *
 * Token semantics come from Laravel's `Password` broker, which already ships
 * hashing-at-rest, single-use consumption, expiry and per-user throttling with
 * the exact properties this feature needs. These tests pin the BEHAVIOUR, not
 * the implementation — a later change that hand-rolled the token would have to
 * satisfy the same assertions.
 *
 * The genuinely new obligation is AD-4: a completed reset must revoke every
 * refresh-token FAMILY the user holds. `password_changed_at` cannot do it
 * alone, because `POST /api/auth/refresh` runs outside `RejectStaleCredentials`
 * by design — so a stolen refresh cookie would otherwise survive the reset and
 * keep minting fresh access tokens.
 *
 * REQ: password-recovery self-service confirm endpoint;
 *      identity-auth "Out-of-Session Password Reset Invalidates Prior Sessions"
 */

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\RefreshRotateStatus;
use App\Support\Auth\RefreshTokenStore;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

function resetPasswordUser(array $attributes = []): User
{
    $org = Organization::factory()->create();

    return User::factory()->create(array_merge([
        'organization_id' => $org->id,
        'email' => 'reset@example.com',
        'password' => 'old-password-1234',
    ], $attributes));
}

function resetTokenFor(User $user): string
{
    return Password::broker()->createToken($user);
}

test('a valid token sets the new password and the user can log in with it', function (): void {
    $user = resetPasswordUser();
    $token = resetTokenFor($user);

    $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-password-9876',
        'password_confirmation' => 'brand-new-password-9876',
    ])->assertOk();

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'brand-new-password-9876',
    ])->assertOk();
});

test('the old password stops working immediately', function (): void {
    $user = resetPasswordUser();
    $token = resetTokenFor($user);

    $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-password-9876',
        'password_confirmation' => 'brand-new-password-9876',
    ])->assertOk();

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'old-password-1234',
    ])->assertStatus(401);
});

test('a token is SINGLE USE — replaying it fails', function (): void {
    $user = resetPasswordUser();
    $token = resetTokenFor($user);

    $payload = [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-password-9876',
        'password_confirmation' => 'brand-new-password-9876',
    ];

    $this->postJson('/api/auth/reset-password', $payload)->assertOk();

    // A replayed token must not be a second reset. If it were, a link
    // recovered from a mailbox, a proxy log or a browser history would stay
    // live for the whole TTL after the legitimate reset consumed it.
    $this->postJson('/api/auth/reset-password', array_merge($payload, [
        'password' => 'third-password-5555',
        'password_confirmation' => 'third-password-5555',
    ]))->assertStatus(422);

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'third-password-5555',
    ])->assertStatus(401);
});

test('an EXPIRED token fails', function (): void {
    $user = resetPasswordUser();
    $token = resetTokenFor($user);

    // `config/auth.php` passwords.users.expire is in MINUTES.
    $this->travel((int) config('auth.passwords.users.expire') + 1)->minutes();

    $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-password-9876',
        'password_confirmation' => 'brand-new-password-9876',
    ])->assertStatus(422);
});

test('a forged token fails', function (): void {
    $user = resetPasswordUser();

    $this->postJson('/api/auth/reset-password', [
        'token' => 'not-a-real-token',
        'email' => $user->email,
        'password' => 'brand-new-password-9876',
        'password_confirmation' => 'brand-new-password-9876',
    ])->assertStatus(422);
});

test('a token minted for one user cannot reset another', function (): void {
    $victim = resetPasswordUser();
    $attacker = resetPasswordUser(['email' => 'attacker@example.com']);

    $attackerToken = resetTokenFor($attacker);

    $this->postJson('/api/auth/reset-password', [
        'token' => $attackerToken,
        'email' => $victim->email,
        'password' => 'attacker-chosen-password',
        'password_confirmation' => 'attacker-chosen-password',
    ])->assertStatus(422);

    $this->postJson('/api/auth/login', [
        'email' => $victim->email,
        'password' => 'attacker-chosen-password',
    ])->assertStatus(401);
});

test('the token is stored HASHED — the raw value is not recoverable from the table', function (): void {
    $user = resetPasswordUser();
    $token = resetTokenFor($user);

    $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');

    expect($stored)->not->toBe($token);
    expect($stored)->not->toContain($token);
});

test('a successful reset revokes EVERY refresh family the user holds — a two-device fixture', function (): void {
    $user = resetPasswordUser();
    $store = app(RefreshTokenStore::class);

    $laptop = $store->issue($user->id);
    $desktop = $store->issue($user->id);

    $this->postJson('/api/auth/reset-password', [
        'token' => resetTokenFor($user),
        'email' => $user->email,
        'password' => 'brand-new-password-9876',
        'password_confirmation' => 'brand-new-password-9876',
    ])->assertOk();

    // Family-scoped revocation would have left the second device alive, and a
    // reset has no session and therefore no `fam` claim to scope by.
    foreach ([$laptop, $desktop] as $issue) {
        expect($store->rotate($issue->familyId, $issue->secret)->status)
            ->toBe(RefreshRotateStatus::Revoked);
    }
});

test('a stolen refresh cookie cannot mint a fresh access token after the reset', function (): void {
    $user = resetPasswordUser();
    $store = app(RefreshTokenStore::class);
    $issue = $store->issue($user->id);

    $this->postJson('/api/auth/reset-password', [
        'token' => resetTokenFor($user),
        'email' => $user->email,
        'password' => 'brand-new-password-9876',
        'password_confirmation' => 'brand-new-password-9876',
    ])->assertOk();

    // The endpoint AD-4 is actually about: /api/auth/refresh runs outside
    // RejectStaleCredentials, so `password_changed_at` is never consulted
    // there. Only revocation closes it.
    $this->withCredentials()
        ->withUnencryptedCookie((string) config('refresh_tokens.cookie.name'), $issue->cookieValue())
        ->withHeader('X-BEAI-Refresh', '1')
        ->postJson('/api/auth/refresh')
        ->assertStatus(401);
});

test('a successful reset stamps password_changed_at so pre-reset access tokens are rejected', function (): void {
    $user = resetPasswordUser();

    $this->postJson('/api/auth/reset-password', [
        'token' => resetTokenFor($user),
        'email' => $user->email,
        'password' => 'brand-new-password-9876',
        'password_confirmation' => 'brand-new-password-9876',
    ])->assertOk();

    // startOfSecond(), matching every existing writer — RejectStaleCredentials
    // compares with a strict `<` against a second-precision `iat`, and a
    // sub-second value would let a token minted in the same second survive.
    $stamp = $user->fresh()->password_changed_at;

    expect($stamp)->not->toBeNull();
    expect($stamp->micro)->toBe(0);
});

test('a DEACTIVATED user cannot complete a reset — it is never a reactivation side channel', function (): void {
    $user = resetPasswordUser();
    $token = resetTokenFor($user);

    $user->forceFill(['deactivated_at' => now()])->save();

    $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-password-9876',
        'password_confirmation' => 'brand-new-password-9876',
    ])->assertStatus(422);

    expect($user->fresh()->deactivated_at)->not->toBeNull();
});

test('a weak or unconfirmed password is refused before the token is spent', function (): void {
    $user = resetPasswordUser();
    $token = resetTokenFor($user);

    $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'short',
        'password_confirmation' => 'different',
    ])->assertStatus(422);

    // The token must survive a validation failure, or a typo would burn the
    // one link a locked-out user has.
    $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-password-9876',
        'password_confirmation' => 'brand-new-password-9876',
    ])->assertOk();
});

test('the reset token never appears in any log channel', function (): void {
    $user = resetPasswordUser();
    $token = resetTokenFor($user);

    $lines = [];
    Log::listen(function ($message) use (&$lines): void {
        $context = is_array($message->context ?? null) ? json_encode($message->context) : '';
        $lines[] = ($message->message ?? '').' '.$context;
    });

    $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-password-9876',
        'password_confirmation' => 'brand-new-password-9876',
    ])->assertOk();

    // Also asserted for the FAILURE path, which is the one most likely to
    // log "what was presented".
    $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-password-9876',
        'password_confirmation' => 'brand-new-password-9876',
    ])->assertStatus(422);

    // Asserted over the JOINED lines, not in a `foreach`. A loop over an empty
    // `$lines` executes zero assertions, and the `assertOk`/`assertStatus`
    // above mask that — the test reads green while the property it is named
    // for goes unchecked. Two assertions over the concatenation always run.
    $logged = implode("\n", $lines);

    expect($logged)->not->toContain($token)
        ->and($logged)->not->toContain('brand-new-password-9876');
});

test('the endpoint is rate limited — the token itself is a brute-force surface', function (): void {
    $user = resetPasswordUser();

    for ($i = 0; $i < 6; $i++) {
        $this->postJson('/api/auth/reset-password', [
            'token' => 'guess-'.$i,
            'email' => $user->email,
            'password' => 'brand-new-password-9876',
            'password_confirmation' => 'brand-new-password-9876',
        ])->assertStatus(422);
    }

    $this->postJson('/api/auth/reset-password', [
        'token' => 'guess-7',
        'email' => $user->email,
        'password' => 'brand-new-password-9876',
        'password_confirmation' => 'brand-new-password-9876',
    ])->assertStatus(429);
});

test('a platform superadmin reset falls back to the log, because audit_logs.organization_id is NOT NULL', function (): void {
    // Inherited verbatim from ResetUserPasswordCommand::recordAudit(): the
    // table is tenant-scoped by design and a superadmin has no tenant for the
    // row to belong to. Faking a real org's id would misattribute the reset
    // into that org's trail; widening the column would weaken an invariant
    // every other row depends on. A visible log fallback is neither.
    $user = User::factory()->create([
        'organization_id' => null,
        'email' => 'superadmin@example.com',
        'password' => 'old-password-1234',
    ]);

    $lines = [];
    Log::listen(function ($message) use (&$lines): void {
        $lines[] = (string) ($message->message ?? '');
    });

    $this->postJson('/api/auth/reset-password', [
        'token' => resetTokenFor($user),
        'email' => $user->email,
        'password' => 'brand-new-password-9876',
        'password_confirmation' => 'brand-new-password-9876',
    ])->assertOk();

    expect($lines)->toContain('audit.user.password_reset');
    expect(AuditLog::withoutGlobalScopes()->count())->toBe(0);
});

test('an org-scoped self-service reset writes the same audit action as the CLI path', function (): void {
    $user = resetPasswordUser();

    $this->postJson('/api/auth/reset-password', [
        'token' => resetTokenFor($user),
        'email' => $user->email,
        'password' => 'brand-new-password-9876',
        'password_confirmation' => 'brand-new-password-9876',
    ])->assertOk();

    // Same action, same table — the audit trail must not acquire a hole
    // shaped like "the common case" now that the common case is self-service.
    $row = AuditLog::withoutGlobalScopes()
        ->where('action', 'user.password_reset')
        ->where('subject_id', $user->id)
        ->firstOrFail();

    expect($row->organization_id)->toBe($user->organization_id);
});
