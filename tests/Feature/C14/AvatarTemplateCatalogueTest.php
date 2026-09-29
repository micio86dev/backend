<?php

declare(strict_types=1);

/**
 * GET /api/avatar-templates/catalogue — a read-only, admin-only, cached
 * provider inventory endpoint (avatar-template-catalogue PR1, design D1).
 *
 * Gated by the SAME `AvatarTemplatePolicy::viewAny` ability the existing
 * `field-specs` action already uses — this endpoint carries no tenant data
 * of its own (it proxies a platform-level provider account), so the RBAC
 * matrix is asserted here rather than tenancy.
 *
 * REQ: avatar-templates "Provider catalogue is fetchable, cached,
 *      admin-only, and never leaks a secret"
 */

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function catalogueActor(Organization $org, string $role): string
{
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user->assignRole(SpatieRole::firstOrCreate([
        'name' => $role, 'guard_name' => 'api', 'team_id' => $org->id,
    ]));

    return auth('api')->login($user);
}

// ─── 1.11 — RBAC ────────────────────────────────────────────────────────────

test('an unauthenticated caller gets 401', function (): void {
    $this->getJson('/api/avatar-templates/catalogue?provider=heygen&resource=voice')
        ->assertUnauthorized();
});

test('operator and viewer are refused', function (): void {
    $org = Organization::factory()->create();

    foreach (['operator', 'viewer'] as $role) {
        $this->withToken(catalogueActor($org, $role))
            ->getJson('/api/avatar-templates/catalogue?provider=heygen&resource=voice')
            ->assertForbidden();
    }
});

test('an admin gets 200 with the normalized catalogue', function (): void {
    $org = Organization::factory()->create();
    config(['interview.heygen.api_key' => 'TEST_HEYGEN_KEY']);

    Http::fake([
        'api.liveavatar.com/v1/voices*' => Http::response([
            'code' => 100,
            'data' => [
                'count' => 1,
                'results' => [['id' => 'hv1', 'name' => 'Alessandra - IA', 'language' => 'en']],
            ],
            'message' => 'ok',
        ], 200),
    ]);

    $this->withToken(catalogueActor($org, 'admin'))
        ->getJson('/api/avatar-templates/catalogue?provider=heygen&resource=voice')
        ->assertOk()
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.items.0.id', 'hv1')
        ->assertJsonPath('data.items.0.language', 'en');
});

// ─── 1.12 — Validation ──────────────────────────────────────────────────────

test('an unknown provider is a 422', function (): void {
    $org = Organization::factory()->create();

    $this->withToken(catalogueActor($org, 'admin'))
        ->getJson('/api/avatar-templates/catalogue?provider=openai&resource=voice')
        ->assertStatus(422);
});

test('an unknown resource is a 422', function (): void {
    $org = Organization::factory()->create();

    $this->withToken(catalogueActor($org, 'admin'))
        ->getJson('/api/avatar-templates/catalogue?provider=heygen&resource=face')
        ->assertStatus(422);
});

test('a resource that exists but belongs to the OTHER provider is a 422', function (): void {
    $org = Organization::factory()->create();

    // `replica` is a real resource value, just not for HeyGen — the delta
    // spec couples provider and resource ("voice|avatar for heygen;
    // voice|replica for tavus"), not just each field in isolation.
    $this->withToken(catalogueActor($org, 'admin'))
        ->getJson('/api/avatar-templates/catalogue?provider=heygen&resource=replica')
        ->assertStatus(422);
});

// ─── Provider failure degrades, never a 500 ────────────────────────────────

test('a provider failure degrades to 200 with a provider_error state and a safe code, never a 500', function (): void {
    $org = Organization::factory()->create();
    config(['interview.tavus.api_key' => 'TEST_TAVUS_KEY']);

    Http::fake([
        'tavusapi.com/v2/voices*' => Http::response(['message' => 'server error'], 500),
    ]);

    $this->withToken(catalogueActor($org, 'admin'))
        ->getJson('/api/avatar-templates/catalogue?provider=tavus&resource=voice')
        ->assertOk()
        ->assertJsonPath('data.status', 'provider_error')
        ->assertJsonPath('data.code', 'provider_unavailable')
        ->assertJsonPath('data.items', []);
});

// ─── No secret leak ─────────────────────────────────────────────────────────

test('no provider API key ever reaches the response body', function (): void {
    $org = Organization::factory()->create();
    config(['interview.heygen.api_key' => 'SUPER_SECRET_HEYGEN_KEY_999']);

    Http::fake([
        'api.liveavatar.com/v1/voices*' => Http::response([
            'message' => 'Unauthorized: key SUPER_SECRET_HEYGEN_KEY_999 rejected',
        ], 401),
    ]);

    $response = $this->withToken(catalogueActor($org, 'admin'))
        ->getJson('/api/avatar-templates/catalogue?provider=heygen&resource=voice');

    $response->assertOk();
    expect($response->getContent())->not->toContain('SUPER_SECRET_HEYGEN_KEY_999');
});

// ─── Voice-only providers (Cartesia, ElevenLabs) ────────────────────────────

test('an admin lists Cartesia voices, Italian first, and can filter to native Italian only', function (): void {
    $org = Organization::factory()->create();
    config(['services.cartesia.api_key' => 'TEST_CARTESIA_KEY']);

    Http::fake([
        'api.cartesia.ai/voices*' => Http::response([
            'data' => [
                ['id' => 'c-en', 'name' => 'Zed', 'language' => 'en'],
                ['id' => 'c-it', 'name' => 'Giulia', 'language' => 'it'],
            ],
            'has_more' => false,
        ], 200),
    ]);

    $token = catalogueActor($org, 'admin');

    $this->withToken($token)
        ->getJson('/api/avatar-templates/catalogue?provider=cartesia&resource=voice')
        ->assertOk()
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.items.0.id', 'c-it')
        ->assertJsonPath('data.items.0.provider', 'cartesia')
        ->assertJsonPath('data.items.0.italian', 'native')
        ->assertJsonCount(2, 'data.items');

    $this->withToken($token)
        ->getJson('/api/avatar-templates/catalogue?provider=cartesia&resource=voice&italian_only=1')
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.id', 'c-it');
});

test('Cartesia voices are requested with expand[]=preview_file_url and expose it as preview_audio_url', function (): void {
    $org = Organization::factory()->create();
    config(['services.cartesia.api_key' => 'TEST_CARTESIA_KEY']);

    Http::fake([
        'api.cartesia.ai/voices*' => Http::response([
            'data' => [
                ['id' => 'c-it', 'name' => 'Giulia', 'language' => 'it', 'preview_file_url' => 'https://cdn.example.test/giulia.mp3'],
                ['id' => 'c-en', 'name' => 'Zed', 'language' => 'en'],
            ],
            'has_more' => false,
        ], 200),
    ]);

    $this->withToken(catalogueActor($org, 'admin'))
        ->getJson('/api/avatar-templates/catalogue?provider=cartesia&resource=voice')
        ->assertOk()
        ->assertJsonPath('data.items.0.id', 'c-it')
        ->assertJsonPath('data.items.0.preview_audio_url', 'https://cdn.example.test/giulia.mp3')
        ->assertJsonPath('data.items.1.id', 'c-en')
        ->assertJsonPath('data.items.1.preview_audio_url', null);

    Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'expand[]=preview_file_url')
        && str_contains($request->url(), 'limit=100'));
});

test('a voice-only provider has no avatar resource', function (): void {
    $org = Organization::factory()->create();

    $this->withToken(catalogueActor($org, 'admin'))
        ->getJson('/api/avatar-templates/catalogue?provider=elevenlabs&resource=avatar')
        ->assertUnprocessable();
});
