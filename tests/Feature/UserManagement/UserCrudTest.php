<?php

declare(strict_types=1);

/**
 * GET/POST/PATCH /api/users — org-scoped, admin-only CRUD
 * (backoffice-missing-pages D4).
 *
 * REQ: Org-Scoped User CRUD Endpoints, Admin-Only Authorization On Every Verb
 *      (openspec/changes/backoffice-missing-pages/specs/user-management/spec.md)
 */

use App\Models\Organization;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('GET /api/users returns only same-org users (3 org A, 2 org B)', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($orgA, 'admin');

    User::factory()->count(2)->create(['organization_id' => $orgA->id]);
    User::factory()->count(2)->create(['organization_id' => $orgB->id]);

    $response = $this->withToken($token)->getJson('/api/users');

    $response->assertOk();
    // 2 extra + the admin caller themselves = 3.
    expect($response->json('data'))->toHaveCount(3);
    $ids = collect($response->json('data'))->pluck('id');
    foreach (User::where('organization_id', $orgB->id)->pluck('id') as $orgBId) {
        expect($ids)->not->toContain($orgBId);
    }
});

test('admin can create a user', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');

    $response = $this->withToken($token)->postJson('/api/users', [
        'name' => 'New Operator',
        'email' => 'new-operator@example.test',
        'password' => 'a-strong-password-123',
        'role' => 'operator',
    ]);

    $response->assertCreated();
    expect($response->json('data.role'))->toBe('operator');
    expect($response->json('data.email'))->toBe('new-operator@example.test');

    $created = User::where('email', 'new-operator@example.test')->firstOrFail();
    expect($created->organization_id)->toBe($org->id);
});

test('admin can update a user name and role', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    $target = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $target->assignRole(Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'api', 'team_id' => $org->id]));

    $response = $this->withToken($token)->patchJson("/api/users/{$target->id}", [
        'name' => 'Renamed',
        'role' => 'operator',
    ]);

    $response->assertOk();
    expect($response->json('data.name'))->toBe('Renamed');
    expect($response->json('data.role'))->toBe('operator');
});

test('operator and viewer are denied every write verb', function (string $role): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, $role);
    $target = User::factory()->create(['organization_id' => $org->id]);

    $this->withToken($token)->postJson('/api/users', [
        'name' => 'X', 'email' => 'x@example.test', 'password' => 'password123', 'role' => 'viewer',
    ])->assertForbidden();

    $this->withToken($token)->patchJson("/api/users/{$target->id}", ['name' => 'Y'])->assertForbidden();

    $this->withToken($token)->postJson("/api/users/{$target->id}/deactivate")->assertForbidden();

    $this->withToken($token)->postJson("/api/users/{$target->id}/activate")->assertForbidden();
})->with(['operator', 'viewer']);

/**
 * RED — the all-clients 500 (platform-user-management, D4).
 *
 * A superadmin viewing all clients has no organization. `Gate::before` grants
 * them every ability, so `authorize('create', User::class)` waves them through
 * to `requireOrgId()`, which aborted 500 — an internal server error, on a
 * state the product puts one click away in Settings -> Users and roles.
 *
 * It is not a fault in the system. It is a caller in the wrong scope, and the
 * backoffice already renders machine codes in the operator's own language.
 */
test('a superadmin with no acting client is refused legibly, not with a 500', function (): void {
    $superadmin = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
    $token = auth('api')->login($superadmin);

    $response = $this->withToken($token)->postJson('/api/users', [
        'name' => 'Nobody',
        'email' => 'nobody@example.test',
        'password' => 'a-strong-password-123',
        'role' => 'operator',
    ]);

    $response->assertStatus(409);
    expect($response->json('message'))->toBe('organization_context_required');
    expect(User::where('email', 'nobody@example.test')->exists())->toBeFalse();
});

/**
 * The invariant this change must not touch. `UserAdminReader` excludes
 * superadmins in its WHERE clause, unconditionally — teaching the org surface
 * to sometimes include them is exactly what the new platform surface exists to
 * avoid.
 */
test('a superadmin stays invisible to the org-scoped surface', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');

    // Carrying an organization_id on purpose: the exclusion must hold on
    // is_superadmin alone, not by accident of a null org.
    $superadmin = User::factory()->create([
        'organization_id' => $org->id,
        'is_superadmin' => true,
    ]);

    $ids = collect($this->withToken($token)->getJson('/api/users')->json('data'))->pluck('id');
    expect($ids)->not->toContain($superadmin->id);

    $this->withToken($token)
        ->patchJson("/api/users/{$superadmin->id}", ['name' => 'Renamed'])
        ->assertNotFound();
});

/**
 * An admin-set password must end the sessions it replaces — BOTH halves.
 *
 * `password_changed_at` drives `RejectStaleCredentials`, but that middleware
 * never runs on `POST /api/auth/refresh`: routes/api.php gives that route only
 * `RequireRefreshCsrfHeader`, so there is no resolvable user and the guard
 * returns early. A stolen refresh cookie would keep minting access tokens
 * after the reset that was supposed to stop it.
 */
test('an admin-set password revokes the target refresh tokens too', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    $target = User::factory()->create([
        'organization_id' => $org->id,
        'password_changed_at' => null,
    ]);

    $live = RefreshToken::create([
        'user_id' => $target->id,
        'family_id' => (string) Str::uuid(),
        'token_hash' => hash('sha256', (string) Str::uuid()),
        'generation' => 1,
        'absolute_expires_at' => now()->addDays(30),
    ]);

    $this->withToken($token)
        ->patchJson("/api/users/{$target->id}", ['password' => 'a-new-strong-password-123'])
        ->assertOk();

    expect($target->fresh()->password_changed_at)->not->toBeNull();
    expect($live->fresh()->revoked_at)->not->toBeNull();
});

test('a name-only change leaves the target logged in', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    $target = User::factory()->create([
        'organization_id' => $org->id,
        'password_changed_at' => null,
    ]);

    $live = RefreshToken::create([
        'user_id' => $target->id,
        'family_id' => (string) Str::uuid(),
        'token_hash' => hash('sha256', (string) Str::uuid()),
        'generation' => 1,
        'absolute_expires_at' => now()->addDays(30),
    ]);

    $this->withToken($token)
        ->patchJson("/api/users/{$target->id}", ['name' => 'Renamed'])
        ->assertOk();

    expect($live->fresh()->revoked_at)->toBeNull();
});

/**
 * The organization surface answers with codes too.
 *
 * `UserForm` serves BOTH populations through one call site. The platform
 * requests carried codes and these did not, so a duplicate address on the org
 * path printed "The email has already been taken." verbatim under an Italian
 * field label — one call site, two populations, one of them fixed.
 */
test('validation on the org surface answers with machine codes', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    User::factory()->create(['organization_id' => $org->id, 'email' => 'dup@example.test']);

    $response = $this->withToken($token)->postJson('/api/users', [
        'email' => 'dup@example.test',
        'password' => 'short',
        'role' => 'sovereign',
    ]);

    expect($response->json('errors.name.0'))->toBe('name_required');
    expect($response->json('errors.email.0'))->toBe('email_taken');
    expect($response->json('errors.password.0'))->toBe('password_too_short');
    expect($response->json('errors.role.0'))->toBe('role_invalid');
});

/**
 * EVERY key in both messages() arrays, not a sample.
 *
 * The docblocks claim "every declared rule". Six of thirteen keys per array
 * were asserted nowhere, so deleting them left the suite green — and the
 * regression the method exists to prevent is exactly an unmapped rule falling
 * back to "The email field is required." under an Italian label.
 */
test('POST /api/users maps every declared rule to a code', function (array $payload, string $field, string $code): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    User::factory()->create(['organization_id' => $org->id, 'email' => 'dup@example.test']);

    $response = $this->withToken($token)->postJson('/api/users', $payload);

    expect($response->json("errors.{$field}.0"))->toBe($code);
})->with([
    'name missing' => [['email' => 'a@b.test', 'password' => 'a-strong-password', 'role' => 'operator'], 'name', 'name_required'],
    'name not a string' => [['name' => ['x'], 'email' => 'a@b.test', 'password' => 'a-strong-password', 'role' => 'operator'], 'name', 'name_invalid'],
    'name too long' => [['name' => str_repeat('a', 256), 'email' => 'a@b.test', 'password' => 'a-strong-password', 'role' => 'operator'], 'name', 'name_too_long'],
    'email missing' => [['name' => 'A', 'password' => 'a-strong-password', 'role' => 'operator'], 'email', 'email_required'],
    'email malformed' => [['name' => 'A', 'email' => 'not-an-email', 'password' => 'a-strong-password', 'role' => 'operator'], 'email', 'email_invalid'],
    'email taken' => [['name' => 'A', 'email' => 'dup@example.test', 'password' => 'a-strong-password', 'role' => 'operator'], 'email', 'email_taken'],
    'email too long' => [['name' => 'A', 'email' => str_repeat('a', 250).'@b.test', 'password' => 'a-strong-password', 'role' => 'operator'], 'email', 'email_too_long'],
    'password missing' => [['name' => 'A', 'email' => 'a@b.test', 'role' => 'operator'], 'password', 'password_required'],
    'password not a string' => [['name' => 'A', 'email' => 'a@b.test', 'password' => 12345678, 'role' => 'operator'], 'password', 'password_invalid'],
    'password too short' => [['name' => 'A', 'email' => 'a@b.test', 'password' => 'short', 'role' => 'operator'], 'password', 'password_too_short'],
    'role missing' => [['name' => 'A', 'email' => 'a@b.test', 'password' => 'a-strong-password'], 'role', 'role_required'],
    'role not a string' => [['name' => 'A', 'email' => 'a@b.test', 'password' => 'a-strong-password', 'role' => ['operator']], 'role', 'role_invalid'],
    'role outside the allow-list' => [['name' => 'A', 'email' => 'a@b.test', 'password' => 'a-strong-password', 'role' => 'sovereign'], 'role', 'role_invalid'],
]);

test('PATCH /api/users/{id} maps every declared rule to a code', function (array $payload, string $field, string $code): void {
    // Its own array, its own coverage: the store request's cases say nothing
    // about this class.
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    $target = User::factory()->create(['organization_id' => $org->id]);
    User::factory()->create(['organization_id' => $org->id, 'email' => 'dup@example.test']);

    $response = $this->withToken($token)->patchJson("/api/users/{$target->id}", $payload);

    expect($response->json("errors.{$field}.0"))->toBe($code);
})->with([
    'name blank' => [['name' => ''], 'name', 'name_required'],
    'name not a string' => [['name' => ['x']], 'name', 'name_invalid'],
    'name too long' => [['name' => str_repeat('a', 256)], 'name', 'name_too_long'],
    'email blank' => [['email' => ''], 'email', 'email_required'],
    'email malformed' => [['email' => 'not-an-email'], 'email', 'email_invalid'],
    'email taken' => [['email' => 'dup@example.test'], 'email', 'email_taken'],
    'email too long' => [['email' => str_repeat('a', 250).'@b.test'], 'email', 'email_too_long'],
    'password blank' => [['password' => ''], 'password', 'password_required'],
    'password not a string' => [['password' => 12345678], 'password', 'password_invalid'],
    'password too short' => [['password' => 'short'], 'password', 'password_too_short'],
    'role blank' => [['role' => ''], 'role', 'role_required'],
    'role not a string' => [['role' => ['operator']], 'role', 'role_invalid'],
    'role outside the allow-list' => [['role' => 'sovereign'], 'role', 'role_invalid'],
]);

/**
 * `sometimes` alone let an empty string through.
 *
 * The field is optional, but if it IS sent it must carry a value — which is
 * what `sometimes` + `required` means, and what UpdatePlatformUserRequest
 * already did. Without the pair, a PATCH carrying `{"name": ""}` cleared the
 * user's name and answered 200.
 */
test('a blank name on update is refused, not saved', function (): void {
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    $target = User::factory()->create(['organization_id' => $org->id, 'name' => 'Ada Lovelace']);

    $response = $this->withToken($token)->patchJson("/api/users/{$target->id}", ['name' => '']);

    $response->assertUnprocessable();
    expect($response->json('errors.name.0'))->toBe('name_required');
    expect($target->fresh()->name)->toBe('Ada Lovelace');
});

test('omitting a field entirely is still allowed', function (): void {
    // The other half: `sometimes` is what makes a partial update partial.
    $org = Organization::factory()->create();
    ['token' => $token] = authUserAndTokenForRole($org, 'admin');
    $target = User::factory()->create(['organization_id' => $org->id, 'name' => 'Ada Lovelace']);

    $this->withToken($token)
        ->patchJson("/api/users/{$target->id}", ['name' => 'Ada Byron'])
        ->assertOk();

    expect($target->fresh()->name)->toBe('Ada Byron');
});
