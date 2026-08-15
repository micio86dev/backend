<?php

declare(strict_types=1);

/**
 * ProfileAllowListTest (user-profile-self-service).
 *
 * REQ: Singular Self-Resolving Profile Resource; Editable Fields Are An
 * Allow-List
 * (openspec/changes/user-profile-self-service/specs/user-self-service/spec.md)
 */

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('PATCH /api/profile ignores role, organization_id, is_superadmin, deactivated_at while updating name', function (): void {
    $org = Organization::factory()->create();
    ['user' => $user, 'token' => $token] = authUserAndTokenForRole($org, 'operator');
    $otherOrg = Organization::factory()->create();

    $response = $this->withToken($token)->patchJson('/api/profile', [
        'name' => 'New Name',
        'role' => 'admin',
        'organization_id' => $otherOrg->id,
        'is_superadmin' => true,
        'deactivated_at' => null,
    ]);

    $response->assertOk();

    $fresh = $user->fresh();
    expect($fresh->name)->toBe('New Name');
    expect($fresh->hasRole('admin'))->toBeFalse();
    expect($fresh->organization_id)->toBe($org->id);
    expect($fresh->is_superadmin)->toBeFalse();
    expect($fresh->deactivated_at)->toBeNull();
});

test('GET /api/profile always resolves to the caller, never another user', function (): void {
    $org = Organization::factory()->create();
    ['user' => $userA, 'token' => $tokenA] = authUserAndTokenForRole($org, 'viewer');
    $userB = User::factory()->create(['organization_id' => $org->id]);

    $response = $this->withToken($tokenA)->getJson("/api/profile?id={$userB->id}");

    $response->assertOk();
    expect($response->json('data.email'))->toBe($userA->email);
    expect($response->json('data.email'))->not->toBe($userB->email);
});

test('PATCH /api/profile always resolves to the caller, never another user, even with an id in the body', function (): void {
    $org = Organization::factory()->create();
    ['user' => $userA, 'token' => $tokenA] = authUserAndTokenForRole($org, 'viewer');
    $userB = User::factory()->create(['organization_id' => $org->id, 'name' => 'User B']);

    $response = $this->withToken($tokenA)->patchJson('/api/profile', [
        'id' => $userB->id,
        'name' => 'Renamed A',
    ]);

    $response->assertOk();
    expect($userA->fresh()->name)->toBe('Renamed A');
    expect($userB->fresh()->name)->toBe('User B');
});

// CRITICAL 1 (judgment-day verification): $fillable silently backstops
// organization_id/is_superadmin/deactivated_at, and role is not a column at
// all — so the four assertions above stay green even if the controller's
// own enforcement line (`$request->safe()->only(['name','email','locale'])`)
// is weakened to `$request->all()`. `password` is NOT backstopped by that
// accident: it IS $fillable (User.php), so a raw `password` key in the body
// under a weakened `only()` would change the caller's password through an
// UNTHROTTLED endpoint with NO current_password check — a real credential
// bypass, not a theoretical one. This is the one assertion that actually
// exercises the enforcement line design D2 calls "layer 2 of 3".
test('PATCH /api/profile ignores a raw password key — the stored hash is unchanged', function (): void {
    $org = Organization::factory()->create();
    ['user' => $user, 'token' => $token] = authUserAndTokenForRole($org, 'operator');
    $originalHash = $user->password;

    $response = $this->withToken($token)->patchJson('/api/profile', [
        'name' => 'New Name',
        'password' => 'a-completely-different-password',
    ]);

    $response->assertOk();
    expect($user->fresh()->password)->toBe($originalHash);
    expect(Hash::check('a-completely-different-password', $user->fresh()->password))->toBeFalse();
});

test('name, email and locale update together', function (): void {
    $org = Organization::factory()->create();
    ['user' => $user, 'token' => $token] = authUserAndTokenForRole($org, 'viewer');

    $response = $this->withToken($token)->patchJson('/api/profile', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'locale' => 'it',
    ]);

    $response->assertOk();
    $fresh = $user->fresh();
    expect($fresh->name)->toBe('Ada Lovelace');
    expect($fresh->email)->toBe('ada@example.test');
    expect($fresh->locale)->toBe('it');
});
