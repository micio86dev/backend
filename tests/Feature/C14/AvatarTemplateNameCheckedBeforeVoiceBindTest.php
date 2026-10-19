<?php

declare(strict_types=1);

/**
 * Every refusal a template write can raise happens BEFORE the HeyGen external voice is bound
 * (hr-avatar-adapter A10). The bind is an irreversible side effect on LiveAvatar and in the ledger, so a
 * create or an edit refused for a taken name must not have bound anything.
 *
 * Both template controllers: the organization routes (`/api/avatar-templates`, a superadmin acting as the
 * organization is the only caller allowed the voice fields) and the platform routes
 * (`/api/admin/avatar-templates`).
 */

use App\Models\HeygenBoundVoice;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

const NBV_VOICE = '00e9ec78-2002-41dd-8d19-6b1d3b17a461';

beforeEach(function (): void {
    config([
        'interview.heygen.api_key' => 'HEYGEN_KEY_X',
        'services.cartesia.api_key' => 'CARTESIA_KEY_X',
        'interview.preflight.verify_references' => true,
        'interview.heygen.bind_lock_wait_seconds' => 0,
    ]);

    Http::fake([
        'api.liveavatar.com/v1/avatars*' => Http::response(['data' => ['next' => null, 'results' => [['id' => 'av_ok', 'name' => 'A', 'status' => 'ACTIVE']]]], 200),
        'api.liveavatar.com/v1/secrets' => Http::response(['code' => 1000, 'data' => ['id' => 'sec-1', 'secret_name' => 'x'], 'message' => 'ok'], 200),
        'api.liveavatar.com/v1/voices/third_party' => Http::response(['code' => 1000, 'data' => ['voice_id' => 'bound-voice-1'], 'message' => 'ok'], 200),
        'api.liveavatar.com/v1/voices*' => Http::response(['data' => ['next' => null, 'results' => [['id' => 'v', 'name' => 'V', 'status' => 'ACTIVE']]]], 200),
        'api.cartesia.ai/voices*' => Http::response(['data' => [['id' => NBV_VOICE, 'name' => 'Elena', 'language' => 'it']], 'has_more' => false], 200),
    ]);
});

/** @return array<string, mixed> */
function nbvNative(): array
{
    return ['avatarId' => 'av_ok', 'voiceId' => 'v'];
}

/** @return array<string, mixed> */
function nbvExternal(): array
{
    return ['avatarId' => 'av_ok', 'ttsEngine' => 'cartesia', 'ttsExternalVoiceId' => NBV_VOICE];
}

function nbvLiveAvatarWrites(): int
{
    return Http::recorded()
        ->filter(fn (array $pair): bool => $pair[0]->method() !== 'GET' && str_contains($pair[0]->url(), 'api.liveavatar.com'))
        ->count();
}

const NBV_TAKEN = ['name' => ['A template with this name already exists.']];

dataset('templateRoutes', [
    'organization routes' => ['/api/avatar-templates'],
    'platform routes' => ['/api/admin/avatar-templates'],
]);

function nbvToken(string $base): string
{
    return TemplateActors::token($base === '/api/avatar-templates' ? 'acting' : 'bare', Organization::factory()->create());
}

test('a create refused for a taken name binds no voice', function (string $base): void {
    $token = nbvToken($base);
    $this->withToken($token)->postJson($base, ['name' => 'Taken', 'provider' => 'heygen', 'config' => nbvNative()])->assertCreated();

    $response = $this->withToken($token)->postJson($base, ['name' => 'Taken', 'provider' => 'heygen', 'config' => nbvExternal()]);

    expect($response->assertUnprocessable()->json('errors'))->toBe(NBV_TAKEN)
        ->and(nbvLiveAvatarWrites())->toBe(0)
        ->and(HeygenBoundVoice::query()->count())->toBe(0);
})->with('templateRoutes');

test('an edit refused for a taken name binds no voice', function (string $base): void {
    $token = nbvToken($base);
    $this->withToken($token)->postJson($base, ['name' => 'Taken', 'provider' => 'heygen', 'config' => nbvNative()])->assertCreated();
    $id = $this->withToken($token)->postJson($base, ['name' => 'Mine', 'provider' => 'heygen', 'config' => nbvNative()])->assertCreated()->json('data.id');

    $response = $this->withToken($token)->patchJson("{$base}/{$id}", ['name' => 'Taken', 'config' => nbvExternal()]);

    expect($response->assertUnprocessable()->json('errors'))->toBe(NBV_TAKEN)
        ->and(nbvLiveAvatarWrites())->toBe(0)
        ->and(HeygenBoundVoice::query()->count())->toBe(0);
})->with('templateRoutes');

test('a write accepted on every count still binds the voice once', function (string $base): void {
    $token = nbvToken($base);
    $id = $this->withToken($token)->postJson($base, ['name' => 'Fresh', 'provider' => 'heygen', 'config' => nbvExternal()])->assertCreated()->json('data.id');
    $this->withToken($token)->patchJson("{$base}/{$id}", ['name' => 'Renamed', 'config' => nbvExternal()])->assertOk();

    expect(HeygenBoundVoice::query()->count())->toBe(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/voices/third_party'));
})->with('templateRoutes');
