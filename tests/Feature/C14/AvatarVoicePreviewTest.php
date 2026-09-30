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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
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
        ->assertExactJson(['message' => 'voice_preview_unavailable', 'reason' => 'tavus_stock_voice']);

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

// ─── Tavus persona (PAL) variant ────────────────────────────────────────────

const PAL_SECRET = 'sk-SECRET-PERSONA-KEY-123';

/**
 * @param  array<string, mixed>  $tts
 * @return array<string, mixed>
 */
function palBody(array $tts): array
{
    return [
        'pal_id' => 'p1',
        'pal_name' => 'Persona',
        'system_prompt' => 'SECRET-SYSTEM-PROMPT',
        'api_key' => PAL_SECRET,
        'layers' => [
            'llm' => ['model' => 'x', 'api_key' => PAL_SECRET],
            'tts' => $tts + ['api_key' => PAL_SECRET],
        ],
    ];
}

function fakePal(array $tts, ?string $vendorBody = VOICE_PREVIEW_MP3): void
{
    Http::preventStrayRequests();
    Http::fake([
        'tavusapi.com/v2/pals/*' => Http::response(palBody($tts), 200),
        'api.cartesia.ai/tts/bytes' => Http::response($vendorBody, 200),
        'api.elevenlabs.io/v1/text-to-speech/*' => Http::response($vendorBody, 200),
    ]);
}

function palPreview(mixed $test, array $extra = []): TestResponse
{
    return $test->withToken(voicePreviewSuperadmin())
        ->postJson(VOICE_PREVIEW_URI, ['provider' => 'tavus', 'pal_id' => 'p1'] + $extra);
}

beforeEach(function (): void {
    config(['interview.tavus.api_key' => 'TEST_TAVUS_KEY']);
});

test('pal: a cartesia persona resolves to its external voice and is synthesised with the persona model', function (): void {
    fakePal(['tts_engine' => 'cartesia', 'external_voice_id' => 'ext-c-1', 'tts_model_name' => 'sonic-2']);

    $response = palPreview($this);

    $response->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
    expect($response->getContent())->toBe(VOICE_PREVIEW_MP3);

    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://tavusapi.com/v2/pals/p1'
        && $r->method() === 'GET'
        && $r->hasHeader('x-api-key', 'TEST_TAVUS_KEY'));
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.cartesia.ai/tts/bytes'
        && $r['voice'] === ['mode' => 'id', 'id' => 'ext-c-1']
        && $r['model_id'] === 'sonic-2'
        && $r['transcript'] === config('avatar_preview.phrases.it'));
    Http::assertSentCount(2);
});

test('pal: an elevenlabs persona uses the configured model when it has none', function (): void {
    fakePal(['tts_engine' => 'elevenlabs', 'external_voice_id' => 'El-Ext_9']);

    palPreview($this, ['language' => 'en'])->assertOk();

    Http::assertSent(fn (HttpRequest $r): bool => str_starts_with($r->url(), 'https://api.elevenlabs.io/v1/text-to-speech/El-Ext_9')
        && $r['model_id'] === 'eleven_multilingual_v2'
        && $r['language_code'] === 'en');
});

test('pal: unavailable personas answer 422 voice_preview_unavailable with a machine reason and never call a vendor', function (array $tts, string $reason): void {
    fakePal($tts);

    palPreview($this)
        ->assertStatus(422)
        ->assertExactJson(['message' => 'voice_preview_unavailable', 'reason' => $reason]);

    Http::assertSentCount(1);
})->with([
    'tavus-auto' => [['tts_engine' => 'tavus-auto'], 'pal_uses_tavus_voice'],
    'empty engine' => [['tts_engine' => ''], 'pal_uses_tavus_voice'],
    'native voice only' => [['voice_id' => 'tavus-native-1'], 'pal_uses_tavus_voice'],
    'azure' => [['tts_engine' => 'azure', 'voice_id' => 'x'], 'pal_azure_engine'],
    'external engine without a voice' => [['tts_engine' => 'cartesia'], 'pal_no_voice_configured'],
    'no tts layer' => [[], 'pal_no_voice_configured'],
]);

test('pal: a stock Tavus voice_id preview carries a reason too', function (): void {
    Http::fake();

    $this->withToken(voicePreviewSuperadmin())
        ->postJson(VOICE_PREVIEW_URI, ['provider' => 'tavus', 'voice_id' => 'stock'])
        ->assertStatus(422)
        ->assertExactJson(['message' => 'voice_preview_unavailable', 'reason' => 'tavus_stock_voice']);
});

test('pal: a persona Tavus does not know is 404 voice_preview_voice_not_found', function (): void {
    Http::preventStrayRequests();
    Http::fake(['tavusapi.com/v2/pals/*' => Http::response(['message' => 'nope'], 404)]);

    palPreview($this)->assertStatus(404)->assertExactJson(['message' => 'voice_preview_voice_not_found']);
});

test('pal: Tavus failures map to the provider error and are not cached', function (int $status): void {
    Http::preventStrayRequests();
    Http::fake(['tavusapi.com/v2/pals/*' => Http::sequence()
        ->push(['error' => PAL_SECRET], $status)
        ->push(palBody(['tts_engine' => 'tavus-auto']), 200)]);

    palPreview($this)->assertStatus(502)->assertExactJson(['message' => 'voice_preview_provider_error']);
    palPreview($this)->assertStatus(422);
})->with([401, 403, 429, 500]);

test('pal: a Tavus timeout is a provider error', function (): void {
    Http::preventStrayRequests();
    Http::fake(fn () => throw new ConnectionException('timeout'));

    palPreview($this)->assertStatus(502)->assertExactJson(['message' => 'voice_preview_provider_error']);
});

test('pal: a missing Tavus key is 503 and nothing is sent', function (): void {
    config(['interview.tavus.api_key' => '']);
    Http::preventStrayRequests();
    Http::fake();

    palPreview($this)->assertStatus(503)->assertExactJson(['message' => 'voice_preview_provider_not_configured']);
    Http::assertNothingSent();
});

test('pal: exactly one of voice_id and pal_id, and pal_id only with tavus', function (array $payload): void {
    Http::fake();

    $this->withToken(voicePreviewSuperadmin())->postJson(VOICE_PREVIEW_URI, $payload)->assertStatus(422);

    Http::assertNothingSent();
})->with([
    'both' => [['provider' => 'tavus', 'voice_id' => 'v1', 'pal_id' => 'p1']],
    'neither' => [['provider' => 'tavus']],
    'pal_id with cartesia' => [['provider' => 'cartesia', 'pal_id' => 'p1']],
    'pal_id with heygen' => [['provider' => 'heygen', 'pal_id' => 'p1']],
    'bad pal_id' => [['provider' => 'tavus', 'pal_id' => '../pals']],
    'overlong pal_id' => [['provider' => 'tavus', 'pal_id' => str_repeat('a', 41)]],
]);

test('pal: the second identical request makes ZERO HTTP calls, the persona lookup included', function (): void {
    fakePal(['tts_engine' => 'cartesia', 'external_voice_id' => 'ext-c-1']);
    $token = voicePreviewSuperadmin();

    $this->withToken($token)->postJson(VOICE_PREVIEW_URI, ['provider' => 'tavus', 'pal_id' => 'p1'])->assertOk();
    Http::assertSentCount(2);

    $this->withToken($token)->postJson(VOICE_PREVIEW_URI, ['provider' => 'tavus', 'pal_id' => 'p1'])->assertOk();
    Http::assertSentCount(2);
});

test('pal: personas sharing a voice share the audio; a persona whose voice changed is not served stale audio', function (): void {
    $token = voicePreviewSuperadmin();
    Http::preventStrayRequests();
    Http::fake([
        'tavusapi.com/v2/pals/p1' => Http::sequence()
            ->push(palBody(['tts_engine' => 'cartesia', 'external_voice_id' => 'shared']), 200)
            ->push(palBody(['tts_engine' => 'cartesia', 'external_voice_id' => 'changed']), 200),
        'tavusapi.com/v2/pals/p2' => Http::response(palBody(['tts_engine' => 'cartesia', 'external_voice_id' => 'shared']), 200),
        'api.cartesia.ai/tts/bytes' => Http::sequence()
            ->push(VOICE_PREVIEW_MP3, 200)
            ->push(VOICE_PREVIEW_MP3.'-NEW', 200),
    ]);

    $this->withToken($token)->postJson(VOICE_PREVIEW_URI, ['provider' => 'tavus', 'pal_id' => 'p1'])->assertOk();
    $this->withToken($token)->postJson(VOICE_PREVIEW_URI, ['provider' => 'tavus', 'pal_id' => 'p2'])->assertOk();

    Http::assertSentCount(3);
    expect(Storage::allFiles('voice-previews'))->toHaveCount(1);

    // p1's persona now points at another voice; once the lookup cache expires the new voice is synthesised.
    Cache::flush();

    $response = $this->withToken($token)->postJson(VOICE_PREVIEW_URI, ['provider' => 'tavus', 'pal_id' => 'p1']);

    expect($response->getContent())->toBe(VOICE_PREVIEW_MP3.'-NEW')
        ->and(Storage::allFiles('voice-previews'))->toHaveCount(2);
});

test('pal: nothing of the persona body reaches the response, the logs or the caches', function (): void {
    $logged = [];
    Log::listen(function ($event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });
    $token = voicePreviewSuperadmin();
    Http::preventStrayRequests();

    // A failing vendor call logs; an unavailable persona answers with an error body; a good one caches.
    Http::fake([
        'tavusapi.com/v2/pals/bad' => Http::response(palBody(['tts_engine' => 'azure']), 200),
        'tavusapi.com/v2/pals/ok' => Http::response(palBody(['tts_engine' => 'cartesia', 'external_voice_id' => 'v']), 200),
        'api.cartesia.ai/tts/bytes' => Http::sequence()->push(['error' => PAL_SECRET], 500)->push(VOICE_PREVIEW_MP3, 200),
    ]);

    $unavailable = $this->withToken($token)->postJson(VOICE_PREVIEW_URI, ['provider' => 'tavus', 'pal_id' => 'bad']);
    $failed = $this->withToken($token)->postJson(VOICE_PREVIEW_URI, ['provider' => 'tavus', 'pal_id' => 'ok']);

    $good = $this->withToken($token)->postJson(VOICE_PREVIEW_URI, ['provider' => 'tavus', 'pal_id' => 'ok']);

    $everything = $unavailable->getContent().$failed->getContent().$good->headers->__toString()
        .implode('', $logged)
        .json_encode([
            Cache::get('voice-preview:pal:v1:ok'),
            Cache::get('voice-preview:pal:v1:bad'),
        ]);
    foreach (Storage::allFiles('voice-previews') as $file) {
        $everything .= Storage::get($file);
    }

    expect($everything)->not->toContain(PAL_SECRET)
        ->not->toContain('SECRET-SYSTEM-PROMPT')
        ->not->toContain('TEST_TAVUS_KEY')
        ->and($unavailable->status())->toBe(422)
        ->and($failed->status())->toBe(502)
        ->and($good->status())->toBe(200);
});

test('pal: a persona whose model changed is synthesised again, not served the old model audio', function (): void {
    $token = voicePreviewSuperadmin();
    Http::preventStrayRequests();
    Http::fake([
        'tavusapi.com/v2/pals/p1' => Http::sequence()
            ->push(palBody(['tts_engine' => 'cartesia', 'external_voice_id' => 'v', 'tts_model_name' => 'sonic-2']), 200)
            ->push(palBody(['tts_engine' => 'cartesia', 'external_voice_id' => 'v', 'tts_model_name' => 'sonic-3']), 200),
        'api.cartesia.ai/tts/bytes' => Http::sequence()->push('OLD-MODEL-AUDIO', 200)->push('NEW-MODEL-AUDIO', 200),
    ]);

    $first = $this->withToken($token)->postJson(VOICE_PREVIEW_URI, ['provider' => 'tavus', 'pal_id' => 'p1']);
    Cache::flush();
    $second = $this->withToken($token)->postJson(VOICE_PREVIEW_URI, ['provider' => 'tavus', 'pal_id' => 'p1']);

    expect($first->getContent())->toBe('OLD-MODEL-AUDIO')->and($second->getContent())->toBe('NEW-MODEL-AUDIO');
});
