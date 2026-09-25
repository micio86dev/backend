<?php

declare(strict_types=1);

/**
 * Saving a template refuses invalid provider / avatar / voice combinations
 * with a 422 and a machine code per offending key.
 *
 * Provider inventories are FAKED. The check FAILS OPEN when a provider cannot
 * be read, so an outage never blocks a save.
 */

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\ActingOrganization;
use Illuminate\Support\Facades\Http;

function refPlatformToken(Organization $org): string
{
    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
    app(ActingOrganization::class)->set((int) $user->id, $org->id);

    return auth('api')->login($user);
}

function fakeHeygenInventory(array $avatars = ['av_ok'], array $voices = ['vo_ok']): void
{
    $rows = fn (array $ids): array => array_map(fn (string $id): array => ['id' => $id, 'name' => $id, 'status' => 'ACTIVE'], $ids);

    Http::fake([
        'api.liveavatar.com/v1/avatars*' => Http::response(['data' => ['next' => null, 'results' => $rows($avatars)]], 200),
        'api.liveavatar.com/v1/voices*' => Http::response(['data' => ['next' => null, 'results' => $rows($voices)]], 200),
    ]);
}

beforeEach(function (): void {
    config([
        'interview.heygen.api_key' => 'k1',
        'interview.tavus.api_key' => 'k2',
        'services.cartesia.api_key' => 'k3',
        'services.elevenlabs.api_key' => 'k4',
    ]);
});

test('a HeyGen template naming an avatar the provider does not list is refused', function (): void {
    fakeHeygenInventory();
    $org = Organization::factory()->create();

    $response = $this->withToken(refPlatformToken($org))->postJson('/api/avatar-templates', [
        'name' => 'H', 'provider' => 'heygen', 'config' => ['avatarId' => 'av_missing', 'voiceId' => 'vo_ok'],
    ]);

    $response->assertUnprocessable();
    expect($response->json('errors'))->toBe(['config.avatarId' => ['avatar_not_found']]);
});

test('a HeyGen template naming an unknown voice is refused, and a valid pair is accepted', function (): void {
    fakeHeygenInventory();
    $org = Organization::factory()->create();
    $token = refPlatformToken($org);

    $bad = $this->withToken($token)->postJson('/api/avatar-templates', [
        'name' => 'H', 'provider' => 'heygen', 'config' => ['avatarId' => 'av_ok', 'voiceId' => 'vo_missing'],
    ]);
    $bad->assertUnprocessable();
    expect($bad->json('errors'))->toBe(['config.voiceId' => ['voice_not_found']]);

    $this->withToken($token)->postJson('/api/avatar-templates', [
        'name' => 'H2', 'provider' => 'heygen', 'config' => ['avatarId' => 'av_ok', 'voiceId' => 'vo_ok'],
    ])->assertCreated();
});

test('an item created since the cached list is not refused (one uncached re-read)', function (): void {
    $org = Organization::factory()->create();
    $token = refPlatformToken($org);
    $row = fn (string $id): array => ['id' => $id, 'name' => $id, 'status' => 'ACTIVE'];

    // First read primes the 24h cache without the new avatar; the re-read sees it.
    Http::fake([
        'api.liveavatar.com/v1/avatars*' => Http::sequence()
            ->push(['data' => ['next' => null, 'results' => [$row('av_old')]]], 200) // public, primed
            ->push(['data' => ['next' => null, 'results' => []]], 200)               // own, primed
            ->push(['data' => ['next' => null, 'results' => [$row('av_old'), $row('av_new')]]], 200) // public, fresh
            ->push(['data' => ['next' => null, 'results' => []]], 200),              // own, fresh
        'api.liveavatar.com/v1/voices*' => Http::response(['data' => ['next' => null, 'results' => [$row('vo_ok')]]], 200),
    ]);

    $this->withToken($token)->getJson('/api/avatar-templates/catalogue?provider=heygen&resource=avatar')->assertOk();

    $this->withToken($token)->postJson('/api/avatar-templates', [
        'name' => 'H', 'provider' => 'heygen', 'config' => ['avatarId' => 'av_new', 'voiceId' => 'vo_ok'],
    ])->assertCreated();
});

test('a provider outage never blocks a save', function (): void {
    Http::fake(['*' => Http::response(['message' => 'down'], 503)]);
    $org = Organization::factory()->create();

    $this->withToken(refPlatformToken($org))->postJson('/api/avatar-templates', [
        'name' => 'H', 'provider' => 'heygen', 'config' => ['avatarId' => 'anything', 'voiceId' => 'anything'],
    ])->assertCreated();
});

test('a Tavus template with an unknown face is refused', function (): void {
    Http::fake([
        'tavusapi.com/v2/faces*' => Http::response(['data' => [['face_id' => 'f_ok', 'face_name' => 'F', 'status' => 'completed']], 'total_count' => 1], 200),
    ]);
    $org = Organization::factory()->create();

    $response = $this->withToken(refPlatformToken($org))->postJson('/api/avatar-templates', [
        'name' => 'T', 'provider' => 'tavus', 'config' => ['faceId' => 'f_missing', 'palId' => 'p1'],
    ]);

    $response->assertUnprocessable();
    expect($response->json('errors'))->toBe(['config.faceId' => ['avatar_not_found']]);
});

test('Tavus TTS engine and external voice must be paired, and the voice must exist at the vendor', function (): void {
    Http::fake([
        'tavusapi.com/v2/faces*' => Http::response(['data' => [['face_id' => 'f_ok', 'face_name' => 'F', 'status' => 'completed']], 'total_count' => 1], 200),
        'api.cartesia.ai/voices*' => Http::response(['data' => [['id' => 'c_ok', 'name' => 'Giulia', 'language' => 'it']], 'has_more' => false], 200),
    ]);
    $org = Organization::factory()->create();
    $token = refPlatformToken($org);
    $base = ['faceId' => 'f_ok', 'palId' => 'p1'];

    $post = fn (array $extra) => $this->withToken($token)->postJson('/api/avatar-templates', [
        'name' => 'T'.uniqid(), 'provider' => 'tavus', 'config' => $base + $extra,
    ]);

    expect($post(['ttsEngine' => 'cartesia'])->assertUnprocessable()->json('errors'))
        ->toBe(['config.ttsExternalVoiceId' => ['tts_voice_required']]);

    expect($post(['ttsExternalVoiceId' => 'c_ok'])->assertUnprocessable()->json('errors'))
        ->toBe(['config.ttsEngine' => ['tts_engine_required']]);

    expect($post(['ttsEngine' => 'tavus-auto', 'ttsExternalVoiceId' => 'c_ok'])->assertUnprocessable()->json('errors'))
        ->toBe(['config.ttsEngine' => ['tts_engine_required']]);

    expect($post(['ttsEngine' => 'cartesia', 'ttsExternalVoiceId' => 'c_missing'])->assertUnprocessable()->json('errors'))
        ->toBe(['config.ttsExternalVoiceId' => ['tts_voice_not_found']]);

    $post(['ttsEngine' => 'cartesia', 'ttsExternalVoiceId' => 'c_ok'])->assertCreated();
});

test('updating a template config is validated the same way', function (): void {
    fakeHeygenInventory();
    $org = Organization::factory()->create();
    $token = refPlatformToken($org);

    $id = $this->withToken($token)->postJson('/api/avatar-templates', [
        'name' => 'H', 'provider' => 'heygen', 'config' => ['avatarId' => 'av_ok', 'voiceId' => 'vo_ok'],
    ])->assertCreated()->json('data.id');

    $response = $this->withToken($token)->patchJson("/api/avatar-templates/{$id}", [
        'config' => ['avatarId' => 'av_gone', 'voiceId' => 'vo_ok'],
    ]);

    $response->assertUnprocessable();
    expect($response->json('errors'))->toBe(['config.avatarId' => ['avatar_not_found']]);
});
