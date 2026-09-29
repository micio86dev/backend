<?php

declare(strict_types=1);

/**
 * POST /api/avatar-templates/voice-preview — synthesises (or fetches) a short,
 * SERVER-SIDE sample of one vendor voice so an operator can listen to it before
 * activating a template (avatar-voice-preview P1).
 *
 * Every test runs against `Http::fake`: nothing here may reach a provider.
 *
 * Authorization: the SAME `AvatarTemplatePolicy::create` gate as authoring a
 * template. That policy denies every organization role, so only a superadmin
 * (via `Gate::before`) is served — asserted below rather than assumed.
 */

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

const VOICE_PREVIEW_URI = '/api/avatar-templates/voice-preview';
const VOICE_PREVIEW_MP3 = "ID3\x03\x00\x00\x00\x00\x00\x00FAKE-MP3-BYTES";

function voicePreviewSuperadmin(): string
{
    $user = User::factory()->create(['organization_id' => null]);
    $user->forceFill(['is_superadmin' => true])->save();

    return auth('api')->login($user);
}

function voicePreviewOrgActor(string $role): string
{
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($org->id);
    $user->assignRole(SpatieRole::firstOrCreate(['name' => $role, 'guard_name' => 'api', 'team_id' => $org->id]));

    return auth('api')->login($user);
}

beforeEach(function (): void {
    Storage::fake();
    config([
        'services.cartesia.api_key' => 'TEST_CARTESIA_KEY',
        'services.elevenlabs.api_key' => 'TEST_ELEVENLABS_KEY',
        'interview.heygen.api_key' => 'TEST_HEYGEN_KEY',
    ]);
});

// ─── Authorization ──────────────────────────────────────────────────────────

test('an unauthenticated caller gets 401', function (): void {
    $this->postJson(VOICE_PREVIEW_URI, ['provider' => 'cartesia', 'voice_id' => 'v1'])->assertUnauthorized();
});

test('an org admin, operator and viewer are refused, exactly as they are for creating a template', function (string $role): void {
    Http::fake();

    $this->withToken(voicePreviewOrgActor($role))
        ->postJson(VOICE_PREVIEW_URI, ['provider' => 'cartesia', 'voice_id' => 'v1'])
        ->assertForbidden();

    Http::assertNothingSent();
})->with(['admin', 'operator', 'viewer']);

test('a bare superadmin (no acting client) is served', function (): void {
    Http::fake(['api.cartesia.ai/tts/bytes' => Http::response(VOICE_PREVIEW_MP3, 200)]);

    $this->withToken(voicePreviewSuperadmin())
        ->postJson(VOICE_PREVIEW_URI, ['provider' => 'cartesia', 'voice_id' => 'v1'])
        ->assertOk();
});

// ─── Happy path per provider ────────────────────────────────────────────────

test('cartesia: synthesises the configured Italian phrase with sonic-3 and returns raw audio', function (): void {
    Http::fake(['api.cartesia.ai/tts/bytes' => Http::response(VOICE_PREVIEW_MP3, 200)]);

    $response = $this->withToken(voicePreviewSuperadmin())
        ->postJson(VOICE_PREVIEW_URI, ['provider' => 'cartesia', 'voice_id' => 'cart-voice-1']);

    $response->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
    expect($response->getContent())->toBe(VOICE_PREVIEW_MP3)
        ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=')
        ->and($response->getContent())->not->toContain('TEST_CARTESIA_KEY');

    Http::assertSentCount(1);
    Http::assertSent(function (HttpRequest $request): bool {
        return $request->url() === 'https://api.cartesia.ai/tts/bytes'
            && $request->method() === 'POST'
            && $request->hasHeader('X-API-Key', 'TEST_CARTESIA_KEY')
            && $request->hasHeader('Cartesia-Version')
            && $request['model_id'] === 'sonic-3'
            && $request['transcript'] === config('avatar_preview.phrases.it')
            && $request['voice'] === ['mode' => 'id', 'id' => 'cart-voice-1']
            && $request['language'] === 'it'
            && ($request['output_format']['container'] ?? null) === 'mp3';
    });
});

test('elevenlabs: synthesises with eleven_multilingual_v2 and language_code', function (): void {
    Http::fake(['api.elevenlabs.io/v1/text-to-speech/*' => Http::response(VOICE_PREVIEW_MP3, 200)]);

    $this->withToken(voicePreviewSuperadmin())
        ->postJson(VOICE_PREVIEW_URI, ['provider' => 'elevenlabs', 'voice_id' => 'El3ven_Voice-9', 'language' => 'en'])
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/mpeg');

    Http::assertSentCount(1);
    Http::assertSent(function (HttpRequest $request): bool {
        return str_starts_with($request->url(), 'https://api.elevenlabs.io/v1/text-to-speech/El3ven_Voice-9')
            && $request->method() === 'POST'
            && $request->hasHeader('xi-api-key', 'TEST_ELEVENLABS_KEY')
            && $request['model_id'] === 'eleven_multilingual_v2'
            && $request['text'] === config('avatar_preview.phrases.en')
            && $request['language_code'] === 'en';
    });
});

test('heygen: fetches the generic preview and decodes data.audio_base64', function (): void {
    Http::fake(['api.liveavatar.com/v1/voices/*/preview' => Http::response([
        'code' => 100,
        'data' => ['audio_base64' => base64_encode(VOICE_PREVIEW_MP3)],
        'message' => 'ok',
    ], 200)]);

    $response = $this->withToken(voicePreviewSuperadmin())
        ->postJson(VOICE_PREVIEW_URI, ['provider' => 'heygen', 'voice_id' => '8c0d1f4e-1111-2222-3333-444455556666']);

    $response->assertOk();
    expect($response->getContent())->toBe(VOICE_PREVIEW_MP3);

    Http::assertSentCount(1);
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.liveavatar.com/v1/voices/8c0d1f4e-1111-2222-3333-444455556666/preview'
        && $request->method() === 'GET'
        && $request->hasHeader('X-API-KEY', 'TEST_HEYGEN_KEY'));
});

test('heygen: a data-URI prefixed base64 payload is decoded too', function (): void {
    Http::fake(['api.liveavatar.com/v1/voices/*/preview' => Http::response([
        'data' => ['audio_base64' => 'data:audio/mpeg;base64,'.base64_encode(VOICE_PREVIEW_MP3)],
    ], 200)]);

    $response = $this->withToken(voicePreviewSuperadmin())
        ->postJson(VOICE_PREVIEW_URI, ['provider' => 'heygen', 'voice_id' => 'hv1']);

    expect($response->getContent())->toBe(VOICE_PREVIEW_MP3);
});

test('heygen: an undecodable payload is a provider error', function (): void {
    Http::fake(['api.liveavatar.com/v1/voices/*/preview' => Http::response(['data' => ['audio_base64' => '%%%not base64%%%']], 200)]);

    $this->withToken(voicePreviewSuperadmin())
        ->postJson(VOICE_PREVIEW_URI, ['provider' => 'heygen', 'voice_id' => 'hv1'])
        ->assertStatus(502)
        ->assertExactJson(['message' => 'voice_preview_provider_error']);
});

test('tavus routed through an external cartesia or elevenlabs voice is previewed via that vendor', function (string $engine, string $host): void {
    Http::fake(['*' => Http::response(VOICE_PREVIEW_MP3, 200)]);

    $this->withToken(voicePreviewSuperadmin())
        ->postJson(VOICE_PREVIEW_URI, ['provider' => 'tavus', 'voice_id' => 'ext-voice', 'tts_engine' => $engine])
        ->assertOk();

    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), $host));
})->with([
    ['cartesia', 'api.cartesia.ai'],
    ['elevenlabs', 'api.elevenlabs.io'],
]);

test('tavus stock voices have no preview: 422 voice_preview_unavailable and no provider call', function (?string $engine): void {
    Http::fake();

    $payload = ['provider' => 'tavus', 'voice_id' => 'stock-1'] + ($engine === null ? [] : ['tts_engine' => $engine]);

    $this->withToken(voicePreviewSuperadmin())
        ->postJson(VOICE_PREVIEW_URI, $payload)
        ->assertStatus(422)
        ->assertExactJson(['message' => 'voice_preview_unavailable']);

    Http::assertNothingSent();
})->with([[null], ['tavus-auto'], ['azure']]);

// ─── Cache ──────────────────────────────────────────────────────────────────

test('a second identical request is served from the cache with NO provider call', function (): void {
    Http::fake(['api.cartesia.ai/tts/bytes' => Http::response(VOICE_PREVIEW_MP3, 200)]);
    $token = voicePreviewSuperadmin();
    $payload = ['provider' => 'cartesia', 'voice_id' => 'cached-voice'];

    $this->withToken($token)->postJson(VOICE_PREVIEW_URI, $payload)->assertOk();
    Http::assertSentCount(1);

    $second = $this->withToken($token)->postJson(VOICE_PREVIEW_URI, $payload);

    $second->assertOk();
    expect($second->getContent())->toBe(VOICE_PREVIEW_MP3);
    Http::assertSentCount(1);
    expect(Storage::allFiles('voice-previews'))->toHaveCount(1);
});

test('the cache key separates provider, voice, language and phrase version', function (): void {
    Http::fake(['*' => Http::response(VOICE_PREVIEW_MP3, 200)]);
    $token = voicePreviewSuperadmin();

    $this->withToken($token)->postJson(VOICE_PREVIEW_URI, ['provider' => 'cartesia', 'voice_id' => 'a'])->assertOk();
    $this->withToken($token)->postJson(VOICE_PREVIEW_URI, ['provider' => 'cartesia', 'voice_id' => 'b'])->assertOk();
    $this->withToken($token)->postJson(VOICE_PREVIEW_URI, ['provider' => 'cartesia', 'voice_id' => 'a', 'language' => 'en'])->assertOk();
    config(['avatar_preview.phrase_version' => 'bumped']);
    $this->withToken($token)->postJson(VOICE_PREVIEW_URI, ['provider' => 'cartesia', 'voice_id' => 'a'])->assertOk();

    Http::assertSentCount(4);
    expect(Storage::allFiles('voice-previews'))->toHaveCount(4);
});

test('a failed provider call is never cached', function (): void {
    Http::fake(['api.cartesia.ai/tts/bytes' => Http::sequence()
        ->push('boom', 500)
        ->push(VOICE_PREVIEW_MP3, 200)]);
    $token = voicePreviewSuperadmin();
    $payload = ['provider' => 'cartesia', 'voice_id' => 'flaky'];

    $this->withToken($token)->postJson(VOICE_PREVIEW_URI, $payload)->assertStatus(502);
    $this->withToken($token)->postJson(VOICE_PREVIEW_URI, $payload)->assertOk();
});

// ─── Provider failure mapping ───────────────────────────────────────────────

test('provider failures map to clean codes and never leak the provider body or key', function (int $providerStatus, int $expectedStatus, string $code): void {
    Http::fake(['*' => Http::response(['error' => 'SECRET-PROVIDER-DETAIL TEST_CARTESIA_KEY'], $providerStatus)]);

    $response = $this->withToken(voicePreviewSuperadmin())
        ->postJson(VOICE_PREVIEW_URI, ['provider' => 'cartesia', 'voice_id' => 'v1']);

    $response->assertStatus($expectedStatus)->assertExactJson(['message' => $code]);
    expect($response->getContent())->not->toContain('SECRET-PROVIDER-DETAIL')->not->toContain('TEST_CARTESIA_KEY');
})->with([
    'not found' => [404, 404, 'voice_preview_voice_not_found'],
    'unauthorized' => [401, 502, 'voice_preview_provider_error'],
    'rate limited' => [429, 502, 'voice_preview_provider_error'],
    'server error' => [500, 502, 'voice_preview_provider_error'],
]);

test('an unreachable provider is a provider error', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28 TEST_CARTESIA_KEY'));

    $response = $this->withToken(voicePreviewSuperadmin())
        ->postJson(VOICE_PREVIEW_URI, ['provider' => 'cartesia', 'voice_id' => 'v1']);

    $response->assertStatus(502)->assertExactJson(['message' => 'voice_preview_provider_error']);
});

test('an oversize or empty provider response is rejected and not cached', function (string $body): void {
    config(['avatar_preview.max_bytes' => 16]);
    Http::fake(['*' => Http::response($body, 200)]);

    $this->withToken(voicePreviewSuperadmin())
        ->postJson(VOICE_PREVIEW_URI, ['provider' => 'cartesia', 'voice_id' => 'v1'])
        ->assertStatus(502)
        ->assertExactJson(['message' => 'voice_preview_provider_error']);

    expect(Storage::allFiles('voice-previews'))->toBe([]);
})->with(['oversize' => [str_repeat('x', 17)], 'empty' => ['']]);

test('a missing provider key is 503 and no call is made', function (string $provider, string $configKey): void {
    config([$configKey => '']);
    Http::fake();

    $this->withToken(voicePreviewSuperadmin())
        ->postJson(VOICE_PREVIEW_URI, ['provider' => $provider, 'voice_id' => 'v1'])
        ->assertStatus(503)
        ->assertExactJson(['message' => 'voice_preview_provider_not_configured']);

    Http::assertNothingSent();
})->with([
    ['cartesia', 'services.cartesia.api_key'],
    ['elevenlabs', 'services.elevenlabs.api_key'],
    ['heygen', 'interview.heygen.api_key'],
]);

// ─── Validation ─────────────────────────────────────────────────────────────

test('invalid input is a 422 and never reaches a provider', function (array $payload): void {
    Http::fake();

    $this->withToken(voicePreviewSuperadmin())->postJson(VOICE_PREVIEW_URI, $payload)->assertStatus(422);

    Http::assertNothingSent();
})->with([
    'unknown provider' => [['provider' => 'openai', 'voice_id' => 'v1']],
    'missing voice id' => [['provider' => 'cartesia']],
    'path traversal in voice id' => [['provider' => 'elevenlabs', 'voice_id' => '../../v1/user']],
    'slash in voice id' => [['provider' => 'heygen', 'voice_id' => 'a/b']],
    'query string in voice id' => [['provider' => 'heygen', 'voice_id' => 'a?b=1']],
    'overlong voice id' => [['provider' => 'cartesia', 'voice_id' => str_repeat('a', 81)]],
    'unknown language' => [['provider' => 'cartesia', 'voice_id' => 'v1', 'language' => 'fr']],
    'unknown tts engine' => [['provider' => 'tavus', 'voice_id' => 'v1', 'tts_engine' => 'other']],
]);

test('client text is ignored: the phrase is always the configured one', function (): void {
    Http::fake(['api.cartesia.ai/tts/bytes' => Http::response(VOICE_PREVIEW_MP3, 200)]);

    $this->withToken(voicePreviewSuperadmin())
        ->postJson(VOICE_PREVIEW_URI, [
            'provider' => 'cartesia',
            'voice_id' => 'v1',
            'text' => 'say something harmful',
            'transcript' => 'say something harmful',
        ])
        ->assertOk();

    Http::assertSent(fn (HttpRequest $request): bool => $request['transcript'] === config('avatar_preview.phrases.it'));
});

// ─── Throttle ───────────────────────────────────────────────────────────────

test('the endpoint is throttled per user', function (): void {
    config(['avatar_preview.throttle_per_minute' => 3]);
    RateLimiter::clear('avatar-voice-preview');
    Http::fake(['*' => Http::response(VOICE_PREVIEW_MP3, 200)]);
    $token = voicePreviewSuperadmin();

    foreach (range(1, 3) as $i) {
        $this->withToken($token)->postJson(VOICE_PREVIEW_URI, ['provider' => 'cartesia', 'voice_id' => "v{$i}"])->assertOk();
    }

    $this->withToken($token)
        ->postJson(VOICE_PREVIEW_URI, ['provider' => 'cartesia', 'voice_id' => 'v4'])
        ->assertStatus(429);
});
