<?php

declare(strict_types=1);

/**
 * PasswordChangeTest (user-profile-self-service).
 *
 * REQ: Password Change Requires The Current Password; Password Change
 * Revokes Other Sessions, Not The Acting One
 * (openspec/changes/user-profile-self-service/specs/user-self-service/spec.md)
 *
 * The acting-session assertion follows design.md D3's Testing Strategy table
 * literally: the acting request is re-minted, not exempted — the token
 * STRING that performed the request is retired along with every other prior
 * token, and the brand-new `access_token` returned in the response body is
 * the one that survives. See design.md D3 for the full rationale (a spec
 * wording tension against the literal per-jti phrasing is recorded in the
 * apply report, not silently resolved here).
 */

use App\Http\Requests\UpdatePasswordRequest;
use App\Models\Organization;
use App\Models\User;

// WARNING 5 (judgment-day verification): env('AUTH_GUARD') already defaults to
// 'api' in this application (config/auth.php:19), so an HTTP-level test cannot
// distinguish `current_password` from `current_password:api` — every
// PasswordChangeTest above stays green either way, which is exactly the trap
// design D4 named. This test pins the RULE STRING itself, directly: it fails
// the moment `:api` is dropped, independent of what the env default happens
// to resolve to today.
test('the current_password rule pins the api guard explicitly, not the env default', function (): void {
    $rules = (new UpdatePasswordRequest)->rules();

    expect($rules['current_password'])->toContain('current_password:api');
});

test('wrong current_password is rejected and the stored hash is unchanged', function (): void {
    $org = Organization::factory()->create();
    ['user' => $user, 'token' => $token] = authUserAndTokenForRole($org, 'operator');
    $originalHash = $user->password;

    $response = $this->withToken($token)->putJson('/api/profile/password', [
        'current_password' => 'wrong-password',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ]);

    $response->assertStatus(422);
    expect($user->fresh()->password)->toBe($originalHash);
});

test('correct current_password changes the stored password and a subsequent login succeeds', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token, 'user' => $user] = authUserAndTokenForRole($org, 'operator');

    $response = $this->withToken($token)->putJson('/api/profile/password', [
        'current_password' => 'password',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ]);

    $response->assertOk();

    $login = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'a-brand-new-password',
    ]);
    $login->assertOk();
});

test('password change rejects other sessions; the newly returned token survives, the original acting token does not', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    // Older session (device 1) — minted first, so its `iat` is strictly
    // BEFORE the moment the password changes below (avoids the documented
    // same-second edge case, design.md D3).
    $tokenOtherDevice = (string) auth('api')->login($user);

    $this->travel(2)->seconds();

    // Acting session (device 2) — performs the password change.
    $tokenActingOriginal = (string) auth('api')->login($user);

    resetAuthGuardState();
    $response = $this->withToken($tokenActingOriginal)->putJson('/api/profile/password', [
        'current_password' => 'password',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ]);

    $response->assertOk();
    $newToken = $response->json('access_token');
    expect($newToken)->toBeString();

    // The other device's token stops working — both on /auth/me and /auth/refresh.
    resetAuthGuardState();
    $this->withToken($tokenOtherDevice)->getJson('/api/auth/me')
        ->assertStatus(401)
        ->assertJson(['error' => 'credentials_changed']);

    resetAuthGuardState();
    $this->withToken($tokenOtherDevice)->postJson('/api/auth/refresh')
        ->assertStatus(401);

    // The ORIGINAL acting token string is retired too — re-minted, not
    // exempted (design.md D3).
    resetAuthGuardState();
    $this->withToken($tokenActingOriginal)->getJson('/api/auth/me')
        ->assertStatus(401);

    // The brand-new token returned in the response is the surviving session.
    resetAuthGuardState();
    $this->withToken($newToken)->getJson('/api/auth/me')->assertOk();
});
