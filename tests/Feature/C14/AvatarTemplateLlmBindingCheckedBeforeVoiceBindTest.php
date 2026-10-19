<?php

declare(strict_types=1);

/**
 * The language-model binding is validated BEFORE the HeyGen external voice is bound
 * (hr-avatar-adapter A11). The bind is an irreversible side effect on LiveAvatar and in the ledger, and the
 * binding invariants used to be raised only by the model's `saving` hook, after it: an invalid model or
 * credential next to an external voice still bound the voice and then answered 422.
 *
 * Both template controllers, create and edit. The 422 bodies are the ones the `saving` hook has always
 * produced, so each scenario also pins its body.
 */

use App\Models\AvatarTemplate;
use App\Models\HeygenBoundVoice;
use App\Models\LlmCredential;
use App\Models\LlmModel;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

const LBB_VOICE = '00e9ec78-2002-41dd-8d19-6b1d3b17a461';

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
        'api.cartesia.ai/voices*' => Http::response(['data' => [['id' => LBB_VOICE, 'name' => 'Elena', 'language' => 'it']], 'has_more' => false], 200),
    ]);
});

/** @return array<string, mixed> */
function lbbConfig(bool $external): array
{
    return $external
        ? ['avatarId' => 'av_ok', 'ttsEngine' => 'cartesia', 'ttsExternalVoiceId' => LBB_VOICE]
        : ['avatarId' => 'av_ok', 'voiceId' => 'v'];
}

function lbbModel(string $capability = 'text', bool $available = true): LlmModel
{
    return LlmModel::create([
        'key' => 'model-'.uniqid(),
        'vendor' => 'google',
        'display_name' => 'Model',
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/',
        'capability' => $capability,
        'is_available' => $available,
        'sort_order' => 0,
    ]);
}

function lbbCredential(string $vendor = 'google'): LlmCredential
{
    $credential = new LlmCredential;
    $credential->forceFill([
        'name' => 'Cred-'.uniqid(),
        'vendor' => $vendor,
        'api_key' => 'sk-real-key',
        'key_last_four' => 'real',
        'key_fingerprint' => hash('sha256', uniqid('', true)),
    ]);
    $credential->save();

    return $credential;
}

/**
 * The binding fields of a scenario, and the 422 field/code it must raise.
 *
 * @return array{0: array<string, int|null>, 1: string, 2: string}
 */
function lbbScenario(string $name): array
{
    return match ($name) {
        'model not found' => [['llm_model_id' => 999999, 'llm_credential_id' => null], 'llm_model_id', 'model_not_found'],
        'model unavailable' => [['llm_model_id' => lbbModel(available: false)->id, 'llm_credential_id' => lbbCredential()->id], 'llm_model_id', 'model_unavailable'],
        'native duplex model' => [['llm_model_id' => lbbModel('native_duplex')->id, 'llm_credential_id' => lbbCredential()->id], 'llm_model_id', 'mode_unsupported'],
        'credential not found' => [['llm_model_id' => lbbModel()->id, 'llm_credential_id' => 999999], 'llm_credential_id', 'credential_not_found'],
        'vendor mismatch' => [['llm_model_id' => lbbModel()->id, 'llm_credential_id' => lbbCredential('openai')->id], 'llm_credential_id', 'vendor_mismatch'],
    };
}

function lbbLiveAvatarWrites(): int
{
    return Http::recorded()
        ->filter(fn (array $pair): bool => $pair[0]->method() !== 'GET' && str_contains($pair[0]->url(), 'api.liveavatar.com'))
        ->count();
}

function lbbToken(string $base): string
{
    return TemplateActors::token($base === '/api/avatar-templates' ? 'acting' : 'bare', Organization::factory()->create());
}

dataset('lbbRoutes', [
    'organization routes' => ['/api/avatar-templates'],
    'platform routes' => ['/api/admin/avatar-templates'],
]);

dataset('lbbScenarios', ['model not found', 'model unavailable', 'native duplex model', 'credential not found', 'vendor mismatch']);

test('a create with an invalid language-model binding binds no voice', function (string $base, string $scenario): void {
    [$binding, $field, $code] = lbbScenario($scenario);

    $response = $this->withToken(lbbToken($base))->postJson($base, ['name' => 'Fresh', 'provider' => 'heygen', 'config' => lbbConfig(true)] + $binding);

    expect($response->assertUnprocessable()->json('errors'))->toBe([$field => [$code]])
        ->and(lbbLiveAvatarWrites())->toBe(0)
        ->and(HeygenBoundVoice::query()->count())->toBe(0)
        ->and(AvatarTemplate::withoutGlobalScopes()->where('name', 'Fresh')->exists())->toBeFalse();
})->with('lbbRoutes')->with('lbbScenarios');

test('an edit with an invalid language-model binding binds no voice', function (string $base, string $scenario): void {
    [$binding, $field, $code] = lbbScenario($scenario);
    $token = lbbToken($base);
    $id = $this->withToken($token)->postJson($base, ['name' => 'Mine', 'provider' => 'heygen', 'config' => lbbConfig(false)])->assertCreated()->json('data.id');

    $response = $this->withToken($token)->patchJson("{$base}/{$id}", ['config' => lbbConfig(true)] + $binding);

    expect($response->assertUnprocessable()->json('errors'))->toBe([$field => [$code]])
        ->and(lbbLiveAvatarWrites())->toBe(0)
        ->and(HeygenBoundVoice::query()->count())->toBe(0)
        ->and(AvatarTemplate::withoutGlobalScopes()->whereKey($id)->value('llm_model_id'))->toBeNull();
})->with('lbbRoutes')->with('lbbScenarios');

test('a valid binding next to an external voice still binds the voice once', function (string $base): void {
    $token = lbbToken($base);
    $binding = ['llm_model_id' => lbbModel()->id, 'llm_credential_id' => lbbCredential()->id];

    $id = $this->withToken($token)->postJson($base, ['name' => 'Fresh', 'provider' => 'heygen', 'config' => lbbConfig(true)] + $binding)->assertCreated()->json('data.id');
    $this->withToken($token)->patchJson("{$base}/{$id}", ['config' => lbbConfig(true)] + $binding)->assertOk();

    expect(HeygenBoundVoice::query()->count())->toBe(1)
        ->and(AvatarTemplate::withoutGlobalScopes()->whereKey($id)->value('llm_model_id'))->toBe($binding['llm_model_id']);
})->with('lbbRoutes');
