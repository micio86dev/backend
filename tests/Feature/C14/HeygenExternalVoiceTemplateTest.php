<?php

declare(strict_types=1);

/**
 * A PLATFORM HeyGen template can name a Cartesia / ElevenLabs voice
 * (`ttsEngine` + `ttsExternalVoiceId`), exactly as a Tavus template can.
 *
 * What these tests pin, each from a fact seen on the LIVE LiveAvatar API on
 * 2026-10-03 (see `HeygenVoiceRegistrar`):
 *  - the bind is not idempotent, so two saves must make ONE bind call;
 *  - LiveAvatar accepts any provider voice id, so an id the vendor catalogue
 *    does not list is refused BEFORE any LiveAvatar call;
 *  - the bound voice reports `language: en` whatever it speaks, so the spoken
 *    language keeps coming from the project (`avatar_persona.language`).
 *
 * Nothing here reaches a network: every provider is `Http::fake`d.
 */

use App\Exceptions\ProviderException;
use App\Models\AvatarTemplate;
use App\Models\HeygenBoundVoice;
use App\Models\InterviewSession;
use App\Models\Organization;
use App\Services\Provider\HeygenProvider;
use App\Services\Provider\ProviderPreflight;
use App\Services\Provider\QuestionContext;
use App\Support\AvatarTemplates\ProviderFieldSpecs;
use App\Support\AvatarTemplates\TemplatePayload;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

const HEV_CARTESIA_VOICE = '00e9ec78-2002-41dd-8d19-6b1d3b17a461';

/** @return array<string, mixed> */
function hevConfig(array $overrides = []): array
{
    return array_merge(['avatarId' => 'av_ok', 'ttsEngine' => 'cartesia', 'ttsExternalVoiceId' => HEV_CARTESIA_VOICE], $overrides);
}

function hevFake(array $extra = []): void
{
    $liveavatar = [
        'api.liveavatar.com/v1/avatars*' => Http::response(['data' => ['next' => null, 'results' => [['id' => 'av_ok', 'name' => 'A', 'status' => 'ACTIVE']]]], 200),
        'api.liveavatar.com/v1/secrets' => Http::response(['code' => 1000, 'data' => ['id' => 'sec-1', 'secret_name' => 'x'], 'message' => 'ok'], 200),
        'api.liveavatar.com/v1/voices/third_party' => Http::response(['code' => 1000, 'data' => ['voice_id' => 'bound-voice-1'], 'message' => 'Voice imported successfully'], 200),
        'api.liveavatar.com/v1/voices*' => Http::response(['data' => ['next' => null, 'results' => [['id' => 'native', 'name' => 'N', 'status' => 'ACTIVE'], ['id' => 'v', 'name' => 'V', 'status' => 'ACTIVE']]]], 200),
        'api.cartesia.ai/voices*' => Http::response(['data' => [['id' => HEV_CARTESIA_VOICE, 'name' => 'Elena', 'language' => 'it']], 'has_more' => false], 200),
        'api.elevenlabs.io/v2/voices*' => Http::response(['voices' => [['voice_id' => 'EL_voice_1', 'name' => 'Giulia', 'labels' => ['language' => 'it']]], 'has_more' => false], 200),
    ];

    Http::fake($extra + $liveavatar);
}

function hevCalls(string $method, string $urlPart): int
{
    return Http::recorded()
        ->filter(fn (array $pair): bool => $pair[0]->method() === $method && str_contains($pair[0]->url(), $urlPart))
        ->count();
}

function hevLiveAvatarWrites(): int
{
    return Http::recorded()
        ->filter(fn (array $pair): bool => $pair[0]->method() !== 'GET' && str_contains($pair[0]->url(), 'api.liveavatar.com'))
        ->count();
}

function hevPlatformToken(): string
{
    return TemplateActors::token('bare', Organization::factory()->create());
}

/** @return array<string, mixed> */
function hevPayload(array $config, string $name = 'Platform HeyGen'): array
{
    return ['name' => $name, 'provider' => 'heygen', 'config' => $config];
}

beforeEach(function (): void {
    config([
        'interview.heygen.api_key' => 'HEYGEN_KEY_X',
        'services.cartesia.api_key' => 'CARTESIA_KEY_X',
        'services.elevenlabs.api_key' => 'ELEVEN_KEY_X',
        'interview.preflight.verify_references' => true,
        'interview.heygen.bind_lock_wait_seconds' => 0,
    ]);
});

// ─── Field specs ─────────────────────────────────────────────────────────────

test('the platform field specs carry the platform-only voice fields and the organization specs do not', function (): void {
    $org = Organization::factory()->create();

    $platform = collect($this->withToken(hevPlatformToken())->getJson('/api/admin/avatar-templates/field-specs')->assertOk()->json('data.heygen'))->keyBy('key');
    $organization = collect($this->withToken(TemplateActors::token('admin', $org))->getJson('/api/avatar-templates/field-specs')->assertOk()->json('data.heygen'))->keyBy('key');

    expect($platform->has('ttsEngine'))->toBeTrue()
        ->and($platform['ttsEngine']['options'])->toBe(['none', 'cartesia', 'elevenlabs'])
        ->and($platform['ttsEngine']['platform_only'])->toBeTrue()
        ->and($platform['ttsExternalVoiceId']['platform_only'])->toBeTrue()
        ->and($platform['voiceId']['superseded_by_key'])->toBe('ttsEngine')
        ->and($platform['voiceId']['superseded_by_values'])->toBe(['cartesia', 'elevenlabs'])
        ->and($organization->has('ttsEngine'))->toBeFalse()
        ->and($organization->has('ttsExternalVoiceId'))->toBeFalse()
        ->and($organization->has('voiceId'))->toBeTrue();
});

test('the platform field specs are superadmin only', function (): void {
    $this->getJson('/api/admin/avatar-templates/field-specs')->assertUnauthorized();

    $org = Organization::factory()->create();

    foreach (['admin', 'operator', 'viewer'] as $role) {
        $this->withToken(TemplateActors::token($role, $org))->getJson('/api/admin/avatar-templates/field-specs')->assertForbidden();
    }
});

// ─── Saving: validation ──────────────────────────────────────────────────────

test('a superadmin saves a HeyGen template with a Cartesia voice and no native voice id', function (): void {
    hevFake();

    $response = $this->withToken(hevPlatformToken())->postJson('/api/admin/avatar-templates', hevPayload(hevConfig()))->assertCreated();

    $stored = AvatarTemplate::platformOnly()->findOrFail($response->json('data.id'));

    expect($stored->config)->toBe(hevConfig())
        ->and($stored->config)->not->toHaveKey('voiceId');
});

test('an engine with no voice, a voice with no engine and a native voice beside an engine are each refused with their own code', function (): void {
    hevFake();
    $token = hevPlatformToken();

    $noVoice = $this->withToken($token)->postJson('/api/admin/avatar-templates', hevPayload(['avatarId' => 'av_ok', 'ttsEngine' => 'cartesia']));
    $noEngine = $this->withToken($token)->postJson('/api/admin/avatar-templates', hevPayload(['avatarId' => 'av_ok', 'voiceId' => 'v', 'ttsExternalVoiceId' => HEV_CARTESIA_VOICE]));
    $noneWithVoice = $this->withToken($token)->postJson('/api/admin/avatar-templates', hevPayload(['avatarId' => 'av_ok', 'voiceId' => 'v', 'ttsEngine' => 'none', 'ttsExternalVoiceId' => HEV_CARTESIA_VOICE]));
    $both = $this->withToken($token)->postJson('/api/admin/avatar-templates', hevPayload(hevConfig(['voiceId' => 'native'])));
    $neither = $this->withToken($token)->postJson('/api/admin/avatar-templates', hevPayload(['avatarId' => 'av_ok', 'ttsEngine' => 'none']));

    expect($noVoice->assertUnprocessable()->json('errors'))->toBe(['config.ttsExternalVoiceId' => ['tts_voice_required']])
        ->and($noEngine->assertUnprocessable()->json('errors'))->toHaveKey('config.ttsEngine')
        ->and($noEngine->json('errors')['config.ttsEngine'])->toBe(['tts_engine_required'])
        ->and($noneWithVoice->assertUnprocessable()->json('errors')['config.ttsEngine'])->toBe(['tts_engine_required'])
        ->and($both->assertUnprocessable()->json('errors'))->toBe(['config.voiceId' => ['superseded_by_tts_engine']])
        ->and($neither->assertUnprocessable()->json('errors'))->toBe(['config.voiceId' => ['required']]);

    expect(hevLiveAvatarWrites())->toBe(0);
});

test('Cartesia refuses the ElevenLabs-only voice settings, ElevenLabs accepts them', function (): void {
    hevFake();
    $token = hevPlatformToken();

    $cartesia = $this->withToken($token)->postJson('/api/admin/avatar-templates', hevPayload(hevConfig(['voiceStability' => 0.5, 'voiceStyle' => 0.2, 'voiceSpeed' => 1.1])));

    expect($cartesia->assertUnprocessable()->json('errors'))->toBe([
        'config.voiceStability' => ['tts_setting_unsupported'],
        'config.voiceStyle' => ['tts_setting_unsupported'],
    ]);

    $this->withToken($token)->postJson('/api/admin/avatar-templates', hevPayload(
        ['avatarId' => 'av_ok', 'ttsEngine' => 'elevenlabs', 'ttsExternalVoiceId' => 'EL_voice_1', 'voiceStability' => 0.5, 'voiceStyle' => 0.2, 'voiceSpeed' => 1.1],
        'Eleven',
    ))->assertCreated();
});

test('a voice the vendor catalogue does not list is refused and NOT ONE LiveAvatar call is made', function (): void {
    hevFake();

    $response = $this->withToken(hevPlatformToken())->postJson('/api/admin/avatar-templates', hevPayload(hevConfig(['ttsExternalVoiceId' => 'not-a-real-voice-id'])));

    expect($response->assertUnprocessable()->json('errors'))->toBe(['config.ttsExternalVoiceId' => ['tts_voice_not_found']])
        ->and(hevLiveAvatarWrites())->toBe(0)
        ->and(HeygenBoundVoice::query()->count())->toBe(0)
        ->and(AvatarTemplate::platformOnly()->count())->toBe(0);
});

test('with the vendor catalogue unreadable the voice cannot be verified, so nothing is bound', function (): void {
    hevFake(['api.cartesia.ai/voices*' => Http::response(['message' => 'down'], 503)]);

    $response = $this->withToken(hevPlatformToken())->postJson('/api/admin/avatar-templates', hevPayload(hevConfig()));

    expect($response->assertUnprocessable()->json('errors'))->toBe(['config.ttsExternalVoiceId' => ['tts_voice_unverifiable']])
        ->and(hevLiveAvatarWrites())->toBe(0);
});

test('the verification is not skipped by the references kill switch: binding is irreversible', function (): void {
    config(['interview.preflight.verify_references' => false]);
    hevFake();

    $response = $this->withToken(hevPlatformToken())->postJson('/api/admin/avatar-templates', hevPayload(hevConfig(['ttsExternalVoiceId' => 'nope'])));

    expect($response->assertUnprocessable()->json('errors'))->toBe(['config.ttsExternalVoiceId' => ['tts_voice_not_found']])
        ->and(hevLiveAvatarWrites())->toBe(0);
});

// ─── Saving: binding ─────────────────────────────────────────────────────────

test('saving binds the voice on LiveAvatar once, and saving the same voice again binds nothing', function (): void {
    hevFake();
    $token = hevPlatformToken();

    $id = $this->withToken($token)->postJson('/api/admin/avatar-templates', hevPayload(hevConfig()))->assertCreated()->json('data.id');
    $this->withToken($token)->patchJson("/api/admin/avatar-templates/{$id}", ['name' => 'Renamed', 'config' => hevConfig(['voiceSpeed' => 1.05])])->assertOk();
    $this->withToken($token)->postJson('/api/admin/avatar-templates', hevPayload(hevConfig(), 'A second template, same voice'))->assertCreated();

    expect(hevCalls('POST', '/voices/third_party'))->toBe(1)
        ->and(hevCalls('POST', '/v1/secrets'))->toBe(1)
        ->and(HeygenBoundVoice::query()->where(['engine' => 'cartesia', 'provider_voice_id' => HEV_CARTESIA_VOICE])->count())->toBe(1);
});

test('a bind LiveAvatar refuses is a clear 422 on the voice field, with no template and no half-state', function (): void {
    hevFake(['api.liveavatar.com/v1/voices/third_party' => Http::response(['code' => 5000, 'data' => null, 'message' => 'boom'], 500)]);

    $response = $this->withToken(hevPlatformToken())->postJson('/api/admin/avatar-templates', hevPayload(hevConfig()));

    expect($response->assertUnprocessable()->json('errors'))->toBe(['config.ttsExternalVoiceId' => ['tts_voice_bind_failed']])
        ->and(AvatarTemplate::platformOnly()->count())->toBe(0)
        ->and(HeygenBoundVoice::query()->count())->toBe(0);
});

test('a missing platform vendor key is a clear 422, not a 500', function (): void {
    config(['services.cartesia.api_key' => '']);
    hevFake();

    $response = $this->withToken(hevPlatformToken())->postJson('/api/admin/avatar-templates', hevPayload(hevConfig()));

    expect($response->assertUnprocessable()->json('errors'))->toHaveKey('config.ttsExternalVoiceId');
    expect(AvatarTemplate::platformOnly()->count())->toBe(0);
});

test('a template without an external engine never touches LiveAvatar voices', function (): void {
    hevFake();

    $this->withToken(hevPlatformToken())->postJson('/api/admin/avatar-templates', hevPayload(['avatarId' => 'av_ok', 'voiceId' => 'native', 'ttsEngine' => 'none']))->assertCreated();

    expect(hevLiveAvatarWrites())->toBe(0);
});

// ─── Authorization: platform only ────────────────────────────────────────────

test('the organization routes refuse the platform-only voice fields, even for a superadmin acting as the organization', function (): void {
    hevFake();
    $org = Organization::factory()->create();
    $token = TemplateActors::token('acting', $org);

    $create = $this->withToken($token)->postJson('/api/avatar-templates', hevPayload(hevConfig()));

    expect($create->assertUnprocessable()->json('errors'))->toBe([
        'config.ttsEngine' => ['platform_only'],
        'config.ttsExternalVoiceId' => ['platform_only'],
    ]);

    $own = TenantContextScope::runFor($org->id, fn () => AvatarTemplate::create([
        'name' => 'Own', 'provider' => 'heygen', 'config' => ['avatarId' => 'av_ok', 'voiceId' => 'native'],
    ]));

    $update = $this->withToken($token)->patchJson("/api/avatar-templates/{$own->id}", ['config' => hevConfig()]);

    expect($update->assertUnprocessable()->json('errors'))->toHaveKey('config.ttsEngine')
        ->and(hevLiveAvatarWrites())->toBe(0)
        ->and(HeygenBoundVoice::query()->count())->toBe(0);
});

test('an organization admin cannot write templates at all, so cannot reach the voice fields', function (): void {
    hevFake();
    $org = Organization::factory()->create();
    $token = TemplateActors::token('admin', $org);

    $this->withToken($token)->postJson('/api/avatar-templates', hevPayload(hevConfig()))->assertForbidden();

    expect(hevLiveAvatarWrites())->toBe(0)
        ->and(HeygenBoundVoice::query()->count())->toBe(0);
});

test('importing a template with the platform-only voice fields into an organization is refused', function (): void {
    hevFake();
    $org = Organization::factory()->create();

    $response = $this->withToken(TemplateActors::token('acting', $org))->postJson('/api/avatar-templates/import', [
        'schema' => 'beai.avatar-template/1',
        'templates' => [['name' => 'Imported', 'provider' => 'heygen', 'config' => hevConfig()]],
    ]);

    $response->assertUnprocessable();
    expect(TenantContextScope::runFor($org->id, fn () => AvatarTemplate::where('name', 'Imported')->count()))->toBe(0)
        ->and(hevLiveAvatarWrites())->toBe(0);
});

test('a platform template that uses an external voice cannot be copied into an organization', function (): void {
    hevFake();
    $org = Organization::factory()->create();
    $token = hevPlatformToken();

    $id = $this->withToken($token)->postJson('/api/admin/avatar-templates', hevPayload(hevConfig()))->assertCreated()->json('data.id');

    $this->withToken($token)->postJson("/api/admin/avatar-templates/{$id}/duplicate", ['target_organization_ids' => [$org->id]])
        ->assertUnprocessable()
        ->assertJsonPath('errors.template.0', 'source_config_invalid');

    expect(TenantContextScope::runFor($org->id, fn () => AvatarTemplate::count()))->toBe(0);
});

// ─── Deleting keeps what was bound ───────────────────────────────────────────

test('deleting a template keeps the bound voice and the secret and makes no LiveAvatar call', function (): void {
    hevFake();
    $token = hevPlatformToken();

    $id = $this->withToken($token)->postJson('/api/admin/avatar-templates', hevPayload(hevConfig()))->assertCreated()->json('data.id');
    $writesBefore = hevLiveAvatarWrites();

    $this->withToken($token)->deleteJson("/api/admin/avatar-templates/{$id}")->assertNoContent();

    expect(hevLiveAvatarWrites())->toBe($writesBefore)
        ->and(hevCalls('DELETE', 'api.liveavatar.com'))->toBe(0)
        ->and(HeygenBoundVoice::query()->count())->toBe(1);
});

// ─── The payload ─────────────────────────────────────────────────────────────

test('a Cartesia voice sends the bound id and the Cartesia-discriminated settings with the pinned default model', function (): void {
    $payload = TemplatePayload::heygen(['avatarId' => 'a', 'ttsEngine' => 'cartesia', 'ttsExternalVoiceId' => 'cv', 'voiceSpeed' => 1.1], 'bound-1');

    expect($payload)->toBe([
        'avatar_id' => 'a',
        'avatar_persona' => ['voice_id' => 'bound-1'],
        'voice_settings' => ['provider' => 'cartesia', 'speed' => 1.1, 'model' => 'sonic-3.5'],
    ]);
});

test('an ElevenLabs voice sends the elevenLabs-discriminated settings with the pinned default model', function (): void {
    $payload = TemplatePayload::heygen([
        'avatarId' => 'a', 'ttsEngine' => 'elevenlabs', 'ttsExternalVoiceId' => 'ev',
        'voiceSpeed' => 1.0, 'voiceStability' => 0.6, 'voiceSimilarityBoost' => 0.7, 'voiceStyle' => 0.1, 'voiceUseSpeakerBoost' => false,
    ], 'bound-2');

    expect($payload['avatar_persona'])->toBe(['voice_id' => 'bound-2'])
        ->and($payload['voice_settings'])->toBe([
            'provider' => 'elevenLabs', 'speed' => 1.0, 'stability' => 0.6, 'similarity_boost' => 0.7, 'style' => 0.1,
            'use_speaker_boost' => false, 'model' => 'eleven_flash_v2_5',
        ]);
});

test('the models sent are never sonic-2, which has no Italian', function (): void {
    expect(ProviderFieldSpecs::HEYGEN_TTS_DEFAULT_MODEL)->toBe(['cartesia' => 'sonic-3.5', 'elevenlabs' => 'eleven_flash_v2_5']);
});

test('a template without an external engine produces the exact payload it always did', function (): void {
    $payload = TemplatePayload::heygen([
        'avatarId' => 'a', 'voiceId' => 'native', 'interactivityType' => 'CONVERSATIONAL', 'maxSessionDurationSec' => 600,
        'videoQuality' => 'high', 'videoEncoding' => 'H264', 'voiceSpeed' => 1.0, 'voiceStability' => 0.5,
        'voiceSimilarityBoost' => 0.5, 'voiceStyle' => 0.0, 'voiceUseSpeakerBoost' => true,
    ]);

    expect($payload)->toBe([
        'avatar_id' => 'a',
        'avatar_persona' => ['voice_id' => 'native'],
        'interactivity_type' => 'CONVERSATIONAL',
        'max_session_duration' => 600,
        'video_settings' => ['quality' => 'high', 'encoding' => 'H264'],
        'voice_settings' => ['speed' => 1.0, 'stability' => 0.5, 'similarity_boost' => 0.5, 'style' => 0.0, 'use_speaker_boost' => true],
    ]);
});

// ─── The session token body ──────────────────────────────────────────────────

function hevSession(): InterviewSession
{
    $session = new InterviewSession;
    $session->forceFill([
        'id' => 77, 'organization_id' => 1, 'participant_id' => 1, 'project_id' => null, 'question_index' => 0,
        'competency_code' => 'PRS', 'framework_version_id' => 1, 'provider' => 'heygen', 'provider_session_ref' => null, 'status' => 'pending',
    ]);

    return $session;
}

/**
 * Issues a session as `$org`'s active HeyGen template would and returns the
 * captured `POST /sessions/token` body.
 *
 * @param  array<string, mixed>  $config
 * @return array<string, mixed>
 */
function hevSessionTokenBody(array $config, string $language = 'it'): array
{
    $org = Organization::factory()->create();
    $captured = [];

    Http::fake(function (Request $request) use (&$captured) {
        if (str_contains($request->url(), '/sessions/token')) {
            $captured = $request->data();

            return Http::response(['data' => ['session_id' => 's', 'session_token' => 't']], 200);
        }

        if (str_contains($request->url(), '/contexts')) {
            return Http::response(['data' => ['id' => 'ctx-1']], 200);
        }

        return Http::response([], 200);
    });

    TenantContextScope::runFor($org->id, function () use ($config, $language): void {
        AvatarTemplate::create(['name' => 'Active', 'provider' => 'heygen', 'config' => $config, 'is_active' => true]);

        (new HeygenProvider)->issue(hevSession(), new QuestionContext(competencyCode: 'PRS', questionIndex: 0, systemPrompt: 'P', language: $language));
    });

    return $captured;
}

test('a bound-voice template sends the bound voice, the discriminated settings and STILL the project language', function (): void {
    HeygenBoundVoice::query()->create(['engine' => 'cartesia', 'provider_voice_id' => 'cv', 'secret_id' => 's', 'voice_id' => 'bound-9']);

    $body = hevSessionTokenBody(['avatarId' => 'a', 'ttsEngine' => 'cartesia', 'ttsExternalVoiceId' => 'cv', 'voiceSpeed' => 1.1], 'it');

    expect($body['avatar_persona']['voice_id'])->toBe('bound-9')
        ->and($body['avatar_persona']['language'])->toBe('it')
        ->and($body['voice_settings'])->toBe(['provider' => 'cartesia', 'speed' => 1.1, 'model' => 'sonic-3.5']);

    $english = hevSessionTokenBody(['avatarId' => 'a', 'ttsEngine' => 'cartesia', 'ttsExternalVoiceId' => 'cv'], 'en');

    expect($english['avatar_persona']['language'])->toBe('en')
        ->and($english['avatar_persona']['voice_id'])->toBe('bound-9');
});

test('a native-voice template sends the byte-identical session token body it always did', function (): void {
    $body = hevSessionTokenBody([
        'avatarId' => 'a', 'voiceId' => 'native', 'voiceSpeed' => 1.1, 'voiceStability' => 0.5, 'videoEncoding' => 'H264', 'maxSessionDurationSec' => 600,
    ], 'it');

    expect($body)->toBe([
        'interactivity_type' => 'CONVERSATIONAL',
        'video_settings' => ['quality' => 'low'],
        'avatar_id' => 'a',
        'avatar_persona' => ['voice_id' => 'native', 'language' => 'it', 'context_id' => 'ctx-1'],
        'mode' => 'FULL',
        'is_sandbox' => false,
    ]);
});

test('when the bound voice cannot be resolved the session start fails loudly instead of using the wrong voice', function (): void {
    $org = Organization::factory()->create();
    Http::fake(['*' => Http::response(['message' => 'down'], 500)]);

    $issue = fn () => TenantContextScope::runFor($org->id, function (): void {
        AvatarTemplate::create(['name' => 'Active', 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'ttsEngine' => 'cartesia', 'ttsExternalVoiceId' => 'cv'], 'is_active' => true]);

        (new HeygenProvider)->issue(hevSession(), new QuestionContext(competencyCode: 'PRS', questionIndex: 0, systemPrompt: 'P', language: 'it'));
    });

    expect($issue)->toThrow(ProviderException::class);
});

test('a ledger row lost since the save is rebound once, then used', function (): void {
    $org = Organization::factory()->create();
    $bodies = [];

    Http::fake(function (Request $request) use (&$bodies) {
        $url = $request->url();

        if (str_contains($url, '/sessions/token')) {
            $bodies[] = $request->data();

            return Http::response(['data' => ['session_id' => 's', 'session_token' => 't']], 200);
        }

        return match (true) {
            str_contains($url, '/secrets') => Http::response(['code' => 1000, 'data' => ['id' => 'sec-1'], 'message' => 'ok'], 200),
            str_contains($url, '/voices/third_party') => Http::response(['code' => 1000, 'data' => ['voice_id' => 'rebound-1'], 'message' => 'ok'], 200),
            str_contains($url, '/contexts') => Http::response(['data' => ['id' => 'ctx-1']], 200),
            default => Http::response([], 200),
        };
    });

    TenantContextScope::runFor($org->id, function (): void {
        AvatarTemplate::create(['name' => 'Active', 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'ttsEngine' => 'cartesia', 'ttsExternalVoiceId' => 'cv'], 'is_active' => true]);

        (new HeygenProvider)->issue(hevSession(), new QuestionContext(competencyCode: 'PRS', questionIndex: 0, systemPrompt: 'P', language: 'it'));
    });

    expect($bodies[0]['avatar_persona']['voice_id'])->toBe('rebound-1')
        ->and(HeygenBoundVoice::query()->count())->toBe(1);
});

// ─── Pre-flight ──────────────────────────────────────────────────────────────

test('the interview pre-flight accepts a template whose voice is external and reports no missing voice', function (): void {
    $org = Organization::factory()->create();
    config(['interview.preflight.verify_references' => false, 'interview.heygen.voice_id' => '']);

    $errors = TenantContextScope::runFor($org->id, function (): array {
        AvatarTemplate::create(['name' => 'Active', 'provider' => 'heygen', 'config' => ['avatarId' => 'a', 'ttsEngine' => 'cartesia', 'ttsExternalVoiceId' => 'cv'], 'is_active' => true]);

        return app(ProviderPreflight::class)->check('heygen', null);
    });

    expect($errors)->toBe([]);
});
