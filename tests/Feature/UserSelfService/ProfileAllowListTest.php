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
use App\Support\ProfilePhotoUrlSigner;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

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

// CRITICAL 2 (user-avatar-image, design D2.1): a column-only assertion here
// would be backstopped by the same accident the note above documents —
// `profile_photo_path` is not $fillable, so it would stay NULL even if the
// controller's own enforcement line were weakened. The danger is not the
// write, it is the READ capability the write unlocks one serialization
// later: a future `photo_url` computed from `$request->input('profile_photo_path')`
// would keep the column clean and still hand back a signed URL for someone
// else's biometric frame. This test therefore asserts on the RESPONSE, not
// just the row: `data.photo_url` is null AND the raw response body does not
// contain the snapshot key as a substring — the two assertions the
// `password` assertion's shape cannot express.
test('a crafted snapshot-shaped path is not accepted, and presigns nothing', function (): void {
    Storage::fake();

    $org = Organization::factory()->create();
    ['user' => $user, 'token' => $token] = authUserAndTokenForRole($org, 'operator');

    $snapshotKey = "{$org->id}/999/1234/".fake()->uuid().'.jpg';
    Storage::put($snapshotKey, "\xFF\xD8\xFF\xE0".str_repeat("\x00", 32));

    $response = $this->withToken($token)->patchJson('/api/profile', [
        'name' => 'New Name',
        'profile_photo_path' => $snapshotKey,
    ]);

    $response->assertOk();
    expect($response->json('data.name'))->toBe('New Name');
    expect($user->fresh()->profile_photo_path)->toBeNull();
    expect($response->json('data.photo_url'))->toBeNull();
    expect($response->getContent())->not->toContain($snapshotKey);
});

// The assertion that survives a controller refactor (design D2.2): it does
// not depend on ProfilePhotoController at all. Bypasses HTTP entirely with a
// forceFill straight onto the row — proving ProfilePhotoUrlSigner refuses a
// foreign key STRUCTURALLY, by prefix, not merely because nothing ever wrote
// it through the allow-list.
test('a snapshot key forced directly onto the row still presigns nothing', function (): void {
    Storage::fake();

    $org = Organization::factory()->create();
    ['user' => $user, 'token' => $token] = authUserAndTokenForRole($org, 'operator');

    $snapshotKey = "{$org->id}/999/1234/".fake()->uuid().'.jpg';
    Storage::put($snapshotKey, "\xFF\xD8\xFF\xE0".str_repeat("\x00", 32));
    $user->forceFill(['profile_photo_path' => $snapshotKey])->save();

    $response = $this->withToken($token)->getJson('/api/profile');

    $response->assertOk();
    expect($response->json('data.photo_url'))->toBeNull();

    // The cheap unit-level sibling of the same claim, inline: the signer
    // itself refuses the key, independent of any HTTP round trip.
    expect(app(ProfilePhotoUrlSigner::class)->urlFor($snapshotKey))->toBeNull();
});
