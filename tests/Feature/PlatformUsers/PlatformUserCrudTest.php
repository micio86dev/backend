<?php

declare(strict_types=1);

/**
 * RED — /api/admin/platform-users (platform-user-management D1/D2/D3).
 *
 * BEAI's own people. A superadmin in the all-clients scope opens Settings ->
 * Users and roles and manages the platform team here; selecting a client
 * switches them back to that organization's users on `/api/users`, which is
 * unchanged.
 *
 * Before this surface existed, the only way to add a second superadmin was
 * `php artisan beai:create-superadmin` — a command whose own description says
 * "Run once. NEVER call from automated seeders." — which means shell access to
 * the production container.
 */

use App\Jobs\SendUserInvitationJob;
use App\Models\Organization;
use App\Models\RefreshToken;
use App\Models\User;
use App\Support\Auth\RefreshTokenStore;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function superadminToken(): array
{
    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

    return ['user' => $user, 'token' => auth('api')->login($user)];
}

function anotherSuperadmin(array $attributes = []): User
{
    return User::factory()->create([
        'organization_id' => null,
        'is_superadmin' => true,
        ...$attributes,
    ]);
}

function platformRefreshToken(int $userId): RefreshToken
{
    return RefreshToken::create([
        'user_id' => $userId,
        'family_id' => (string) Str::uuid(),
        'token_hash' => hash('sha256', (string) Str::uuid()),
        'generation' => 1,
        'absolute_expires_at' => now()->addDays(30),
    ]);
}

describe('authorization', function (): void {
    test('every route refuses an organization admin with 403', function (string $method, string $path): void {
        $org = Organization::factory()->create();
        ['token' => $token] = authUserAndTokenForRole($org, 'admin');
        $victim = anotherSuperadmin();

        $this->withToken($token)
            ->json($method, str_replace('{id}', (string) $victim->id, $path))
            ->assertForbidden();
    })->with([
        ['GET', '/api/admin/platform-users'],
        ['POST', '/api/admin/platform-users'],
        ['PATCH', '/api/admin/platform-users/{id}'],
        ['POST', '/api/admin/platform-users/{id}/deactivate'],
        ['POST', '/api/admin/platform-users/{id}/activate'],
    ]);

    test('an unauthenticated caller is refused', function (): void {
        $this->getJson('/api/admin/platform-users')->assertUnauthorized();
    });
});

describe('listing', function (): void {
    test('it returns platform users, including deactivated ones', function (): void {
        ['user' => $me, 'token' => $token] = superadminToken();
        $peer = anotherSuperadmin();
        $retired = anotherSuperadmin(['deactivated_at' => now()]);

        $org = Organization::factory()->create();
        $orgUser = User::factory()->create(['organization_id' => $org->id]);

        $response = $this->withToken($token)->getJson('/api/admin/platform-users');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->toContain($me->id)->toContain($peer->id)->toContain($retired->id);
        // The whole point of the separate surface: an organization's people
        // are not BEAI's people.
        expect($ids)->not->toContain($orgUser->id);
    });

    test('the payload carries no organization role', function (): void {
        ['token' => $token] = superadminToken();

        $row = $this->withToken($token)->getJson('/api/admin/platform-users')->json('data.0');

        // There is one platform identity. A `role` field here would be a
        // control with one option, and a lie the moment somebody read it as
        // an org role.
        expect($row)->not->toHaveKey('role');
        expect($row)->toHaveKeys(['id', 'name', 'email', 'is_deactivated']);
    });
});

describe('creating', function (): void {
    test('a created platform user belongs to no organization and is a superadmin', function (): void {
        Queue::fake();
        ['token' => $token] = superadminToken();

        $response = $this->withToken($token)->postJson('/api/admin/platform-users', [
            'name' => 'Nuova Collega',
            'email' => 'collega@beai.test',
            'password' => 'a-strong-password-123',
        ]);

        $response->assertCreated();

        $created = User::where('email', 'collega@beai.test')->firstOrFail();
        expect($created->organization_id)->toBeNull();
        expect($created->is_superadmin)->toBeTrue();
        expect($created->getRoleNames())->toBeEmpty();

        Queue::assertPushed(SendUserInvitationJob::class);
    });

    test('a crafted organization_id or is_superadmin in the body is ignored', function (): void {
        Queue::fake();
        $org = Organization::factory()->create();
        ['token' => $token] = superadminToken();

        $this->withToken($token)->postJson('/api/admin/platform-users', [
            'name' => 'Crafted',
            'email' => 'crafted@beai.test',
            'password' => 'a-strong-password-123',
            'organization_id' => $org->id,
            'is_superadmin' => false,
        ])->assertCreated();

        $created = User::where('email', 'crafted@beai.test')->firstOrFail();
        // Neither field is read from the request at all — they are decided by
        // the surface, exactly as `/api/users` decides them for its own.
        expect($created->organization_id)->toBeNull();
        expect($created->is_superadmin)->toBeTrue();
    });

    test('a duplicate email is refused', function (): void {
        ['token' => $token] = superadminToken();
        anotherSuperadmin(['email' => 'taken@beai.test']);

        $this->withToken($token)->postJson('/api/admin/platform-users', [
            'name' => 'Duplicate',
            'email' => 'taken@beai.test',
            'password' => 'a-strong-password-123',
        ])->assertJsonValidationErrorFor('email');
    });

    test('validation answers with machine codes, not English prose', function (): void {
        // A response body is machine-facing, and the backoffice is the only
        // layer that knows the operator's language. Without messages() a
        // duplicate address came back as "The email has already been taken."
        // and was rendered verbatim into an Italian field.
        ['token' => $token] = superadminToken();
        anotherSuperadmin(['email' => 'dup@beai.test']);

        $response = $this->withToken($token)->postJson('/api/admin/platform-users', [
            'email' => 'dup@beai.test',
            'password' => 'short',
        ]);

        expect($response->json('errors.name.0'))->toBe('name_required');
        expect($response->json('errors.email.0'))->toBe('email_taken');
        expect($response->json('errors.password.0'))->toBe('password_too_short');
    });

    test('every declared rule has a code, including the string rule', function (): void {
        // Trivially craftable: a JSON array for name, a number for password.
        // An unmapped rule falls back to Laravel's English, which is exactly
        // the defect messages() exists to end.
        ['token' => $token] = superadminToken();

        $response = $this->withToken($token)->postJson('/api/admin/platform-users', [
            'name' => ['a'],
            'email' => 'ok@beai.test',
            'password' => 12345678,
        ]);

        expect($response->json('errors.name.0'))->toBe('name_invalid');
        expect($response->json('errors.password.0'))->toBe('password_invalid');
    });
});

describe('updating', function (): void {
    test('name, email and password can be changed', function (): void {
        ['token' => $token] = superadminToken();
        $target = anotherSuperadmin(['name' => 'Old Name']);

        $this->withToken($token)
            ->patchJson("/api/admin/platform-users/{$target->id}", [
                'name' => 'New Name',
                'email' => 'new@beai.test',
            ])
            ->assertOk();

        expect($target->fresh()->name)->toBe('New Name');
        expect($target->fresh()->email)->toBe('new@beai.test');
    });

    test('an admin-set password ENDS the sessions it replaces', function (): void {
        // On this surface more than any other: `Gate::before` answers every
        // ability true for these users, so a live token on a stolen laptop is
        // unlimited access to every client on the platform.
        //
        // Two mechanisms, because one does not cover the other.
        // `password_changed_at` drives RejectStaleCredentials, which passes
        // UNCONDITIONALLY while that column is null; refresh tokens are not
        // covered by it at all, since POST /api/auth/refresh is public and
        // never runs that middleware.
        //
        // Asserted on the real rows rather than on a mock — RefreshTokenStore
        // is final, and the effect is what matters anyway.
        ['token' => $token] = superadminToken();
        $target = anotherSuperadmin(['password_changed_at' => null]);
        $live = platformRefreshToken((int) $target->id);

        $this->withToken($token)
            ->patchJson("/api/admin/platform-users/{$target->id}", ['password' => 'a-new-strong-password-123'])
            ->assertOk();

        expect($target->fresh()->password_changed_at)->not->toBeNull();
        expect($live->fresh()->revoked_at)->not->toBeNull();
    });

    test('a name-only change does NOT log anybody out', function (): void {
        // The revocation is tied to the password, not to the request. Ending
        // every session on a typo fix would train operators to avoid the edit
        // screen.
        ['token' => $token] = superadminToken();
        $target = anotherSuperadmin(['password_changed_at' => null]);
        $live = platformRefreshToken((int) $target->id);

        $this->withToken($token)
            ->patchJson("/api/admin/platform-users/{$target->id}", ['name' => 'Renamed'])
            ->assertOk();

        expect($target->fresh()->password_changed_at)->toBeNull();
        expect($live->fresh()->revoked_at)->toBeNull();
    });

    test('the UPDATE request answers with codes too', function (): void {
        // Its own messages(), its own coverage: the store request's tests say
        // nothing about this one, and the two are separate classes.
        ['token' => $token] = superadminToken();
        $target = anotherSuperadmin();
        anotherSuperadmin(['email' => 'taken-on-update@beai.test']);

        $response = $this->withToken($token)->patchJson("/api/admin/platform-users/{$target->id}", [
            'email' => 'taken-on-update@beai.test',
        ]);

        expect($response->json('errors.email.0'))->toBe('email_taken');

        $bad = $this->withToken($token)->patchJson("/api/admin/platform-users/{$target->id}", [
            'name' => ['a'],
        ]);

        expect($bad->json('errors.name.0'))->toBe('name_invalid');
    });

    test('an ORGANISATION user cannot be reached by id', function (): void {
        ['token' => $token] = superadminToken();
        $org = Organization::factory()->create();
        $orgUser = User::factory()->create(['organization_id' => $org->id, 'name' => 'Untouched']);

        $this->withToken($token)
            ->patchJson("/api/admin/platform-users/{$orgUser->id}", ['name' => 'Hijacked'])
            ->assertNotFound();

        expect($orgUser->fresh()->name)->toBe('Untouched');
    });
});

describe('deactivating and activating', function (): void {
    test('a peer can be deactivated and reactivated while another remains', function (): void {
        ['token' => $token] = superadminToken();
        $peer = anotherSuperadmin();

        $this->withToken($token)
            ->postJson("/api/admin/platform-users/{$peer->id}/deactivate")
            ->assertNoContent();
        expect($peer->fresh()->isDeactivated())->toBeTrue();

        $this->withToken($token)
            ->postJson("/api/admin/platform-users/{$peer->id}/activate")
            ->assertNoContent();
        expect($peer->fresh()->isDeactivated())->toBeFalse();
    });

    test('the LAST active superadmin cannot deactivate themselves', function (): void {
        // Unrecoverable from inside the product: no clients console, no
        // platform settings, and no way to create a replacement.
        ['user' => $me, 'token' => $token] = superadminToken();

        $response = $this->withToken($token)
            ->postJson("/api/admin/platform-users/{$me->id}/deactivate");

        $response->assertStatus(422);
        expect($response->json('error'))->toBe('self_deactivation');
        expect($me->fresh()->isDeactivated())->toBeFalse();
    });

    test('a retired peer can be retired again — it removes no survivor', function (): void {
        // The guard counts ACTIVE survivors, so a write against someone
        // already deactivated cannot break its invariant. Refusing here also
        // broke the reactivate/deactivate round trip.
        ['token' => $token] = superadminToken();
        $retired = anotherSuperadmin(['deactivated_at' => now()]);

        $this->withToken($token)
            ->postJson("/api/admin/platform-users/{$retired->id}/deactivate")
            ->assertNoContent();
    });

    test('activating is never guarded — it only ever adds a survivor', function (): void {
        ['token' => $token] = superadminToken();
        $retired = anotherSuperadmin(['deactivated_at' => now()]);

        $this->withToken($token)
            ->postJson("/api/admin/platform-users/{$retired->id}/activate")
            ->assertNoContent();

        expect($retired->fresh()->isDeactivated())->toBeFalse();
    });
});
