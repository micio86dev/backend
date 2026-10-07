<?php

declare(strict_types=1);

/**
 * `HeygenVoiceRegistrar` binds a Cartesia / ElevenLabs voice to LiveAvatar so a
 * HeyGen session can speak with it, and remembers the answer.
 *
 * Every response here is modelled on what the LIVE LiveAvatar API answered on
 * 2026-10-03 (see the class docblock); nothing here reaches the network.
 *
 * The facts the tests encode:
 *  - binding is NOT idempotent at LiveAvatar (three identical binds -> three
 *    voice ids), so BEAI's own ledger is the idempotency;
 *  - LiveAvatar accepts ANY provider voice id with HTTP 200, so validity is
 *    never inferred from a successful bind (that is the caller's catalogue
 *    check);
 *  - an unknown secret id answers HTTP 400 `{code: 4000, data: null}`.
 */

use App\Models\HeygenBoundVoice;
use App\Models\HeygenVendorSecret;
use App\Services\ConversationLlm\HeygenVoiceRegistrar;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

const HVR_VOICE = '00e9ec78-2002-41dd-8d19-6b1d3b17a461';

function hvrRegistrar(): HeygenVoiceRegistrar
{
    return app(HeygenVoiceRegistrar::class);
}

/** @param  list<Request>  $requests */
function hvrCount(string $method, string $urlPart): int
{
    return Http::recorded()
        ->filter(fn (array $pair): bool => $pair[0]->method() === $method && str_contains($pair[0]->url(), $urlPart))
        ->count();
}

function hvrFakeLiveAvatar(string $secretId = 'sec-1', string $voiceId = 'bound-voice-1'): void
{
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['code' => 1000, 'data' => ['id' => $secretId, 'secret_name' => 'x'], 'message' => 'ok'], 200),
        'api.liveavatar.com/v1/voices/third_party' => Http::response(['code' => 1000, 'data' => ['voice_id' => $voiceId], 'message' => 'Voice imported successfully'], 200),
        'api.liveavatar.com/v1/voices/*' => Http::response(['code' => 1000, 'data' => null, 'message' => 'Voice deleted successfully'], 200),
    ]);
}

beforeEach(function (): void {
    config([
        'interview.heygen.api_key' => 'HEYGEN_KEY_X',
        'services.cartesia.api_key' => 'CARTESIA_KEY_X',
        'services.elevenlabs.api_key' => 'ELEVEN_KEY_X',
        'interview.heygen.bind_lock_wait_seconds' => 0,
    ]);
});

test('a Cartesia voice is bound with the platform Cartesia key as a CARTESIA_API_KEY secret', function (): void {
    hvrFakeLiveAvatar('sec-1', 'bound-voice-1');

    $result = hvrRegistrar()->ensureVoice('cartesia', HVR_VOICE);

    expect($result)->toBe(['status' => 'bound', 'voice_id' => 'bound-voice-1']);

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.liveavatar.com/v1/secrets'
        && $r->header('X-API-KEY') === ['HEYGEN_KEY_X']
        && $r['secret_type'] === 'CARTESIA_API_KEY'
        && $r['secret_value'] === 'CARTESIA_KEY_X'
        && is_string($r['secret_name']) && $r['secret_name'] !== '');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.liveavatar.com/v1/voices/third_party'
        && $r['provider_voice_id'] === HVR_VOICE
        && $r['secret_id'] === 'sec-1'
        && is_string($r['name']) && mb_strlen($r['name']) <= 64);

    expect(HeygenBoundVoice::query()->where(['engine' => 'cartesia', 'provider_voice_id' => HVR_VOICE])->value('voice_id'))->toBe('bound-voice-1');
});

test('an ElevenLabs voice uses an ELEVENLABS_API_KEY secret with the platform ElevenLabs key', function (): void {
    hvrFakeLiveAvatar();

    hvrRegistrar()->ensureVoice('elevenlabs', 'EL_voice_1');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.liveavatar.com/v1/secrets'
        && $r['secret_type'] === 'ELEVENLABS_API_KEY'
        && $r['secret_value'] === 'ELEVEN_KEY_X');
});

test('binding the same voice twice makes ONE LiveAvatar bind call (the bind is not idempotent at LiveAvatar)', function (): void {
    hvrFakeLiveAvatar();

    $first = hvrRegistrar()->ensureVoice('cartesia', HVR_VOICE);
    $second = hvrRegistrar()->ensureVoice('cartesia', HVR_VOICE);

    expect($second)->toBe($first)
        ->and(hvrCount('POST', '/voices/third_party'))->toBe(1)
        ->and(hvrCount('POST', '/v1/secrets'))->toBe(1)
        ->and(HeygenBoundVoice::query()->count())->toBe(1);
});

test('the vendor secret is memoised: two voices of one vendor share one secret', function (): void {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['code' => 1000, 'data' => ['id' => 'sec-1'], 'message' => 'ok'], 200),
        'api.liveavatar.com/v1/voices/third_party' => Http::sequence()
            ->push(['code' => 1000, 'data' => ['voice_id' => 'voice-1'], 'message' => 'ok'], 200)
            ->push(['code' => 1000, 'data' => ['voice_id' => 'voice-2'], 'message' => 'ok'], 200),
    ]);

    hvrRegistrar()->ensureVoice('cartesia', HVR_VOICE);
    hvrRegistrar()->ensureVoice('cartesia', 'second-voice');

    expect(hvrCount('POST', '/v1/secrets'))->toBe(1)
        ->and(hvrCount('POST', '/voices/third_party'))->toBe(2)
        ->and(HeygenVendorSecret::query()->where('engine', 'cartesia')->count())->toBe(1);
});

test('the same provider voice id under two engines is two different voices', function (): void {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::sequence()
            ->push(['code' => 1000, 'data' => ['id' => 'sec-c'], 'message' => 'ok'], 200)
            ->push(['code' => 1000, 'data' => ['id' => 'sec-e'], 'message' => 'ok'], 200),
        'api.liveavatar.com/v1/voices/third_party' => Http::sequence()
            ->push(['code' => 1000, 'data' => ['voice_id' => 'voice-c'], 'message' => 'ok'], 200)
            ->push(['code' => 1000, 'data' => ['voice_id' => 'voice-e'], 'message' => 'ok'], 200),
    ]);

    hvrRegistrar()->ensureVoice('cartesia', 'shared-id');
    hvrRegistrar()->ensureVoice('elevenlabs', 'shared-id');

    expect(HeygenBoundVoice::query()->count())->toBe(2)
        ->and(hvrCount('POST', '/v1/secrets'))->toBe(2);
});

test('a missing platform key for the vendor fails cleanly and calls nothing', function (): void {
    Http::fake();
    config(['services.cartesia.api_key' => '']);

    expect(hvrRegistrar()->ensureVoice('cartesia', HVR_VOICE))->toBe(['status' => 'failed', 'code' => 'tts_vendor_key_missing']);

    Http::assertNothingSent();
});

test('a missing platform HeyGen key fails cleanly and calls nothing', function (): void {
    Http::fake();
    config(['interview.heygen.api_key' => '']);

    expect(hvrRegistrar()->ensureVoice('cartesia', HVR_VOICE))->toBe(['status' => 'failed', 'code' => 'tts_provider_unconfigured']);

    Http::assertNothingSent();
});

test('an engine it cannot bind fails cleanly and calls nothing', function (): void {
    Http::fake();

    expect(hvrRegistrar()->ensureVoice('azure', 'x'))->toBe(['status' => 'failed', 'code' => 'tts_engine_unsupported']);

    Http::assertNothingSent();
});

test('a secret registration failure fails cleanly and stores nothing', function (): void {
    Http::fake(['api.liveavatar.com/v1/secrets' => Http::response(['message' => 'down'], 500)]);

    expect(hvrRegistrar()->ensureVoice('cartesia', HVR_VOICE))->toBe(['status' => 'failed', 'code' => 'tts_secret_failed']);

    expect(HeygenVendorSecret::query()->count())->toBe(0)
        ->and(HeygenBoundVoice::query()->count())->toBe(0);
});

test('a bind failure fails cleanly and leaves NO ledger row (no half-state)', function (): void {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['code' => 1000, 'data' => ['id' => 'sec-1'], 'message' => 'ok'], 200),
        'api.liveavatar.com/v1/voices/third_party' => Http::response(['code' => 5000, 'data' => null, 'message' => 'boom'], 500),
    ]);

    expect(hvrRegistrar()->ensureVoice('cartesia', HVR_VOICE))->toBe(['status' => 'failed', 'code' => 'tts_voice_bind_failed']);

    expect(HeygenBoundVoice::query()->count())->toBe(0);
});

test('a 200 without a voice id is a failure, never a row with a null id', function (): void {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['code' => 1000, 'data' => ['id' => 'sec-1'], 'message' => 'ok'], 200),
        'api.liveavatar.com/v1/voices/third_party' => Http::response(['code' => 1000, 'data' => [], 'message' => 'ok'], 200),
    ]);

    expect(hvrRegistrar()->ensureVoice('cartesia', HVR_VOICE)['status'])->toBe('failed');
    expect(HeygenBoundVoice::query()->count())->toBe(0);
});

test('a transport exception never escapes the registrar', function (): void {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    expect(hvrRegistrar()->ensureVoice('cartesia', HVR_VOICE)['status'])->toBe('failed');
});

test('a secret deleted on the LiveAvatar side is recreated once and the bind retried (400 code 4000)', function (): void {
    HeygenVendorSecret::query()->create(['engine' => 'cartesia', 'secret_id' => 'gone-secret']);

    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['code' => 1000, 'data' => ['id' => 'fresh-secret'], 'message' => 'ok'], 200),
        'api.liveavatar.com/v1/voices/third_party' => Http::sequence()
            ->push(['code' => 4000, 'data' => null, 'message' => "Secret with id 'gone-secret' not found in your space"], 400)
            ->push(['code' => 1000, 'data' => ['voice_id' => 'bound-voice-2'], 'message' => 'Voice imported successfully'], 200),
    ]);

    expect(hvrRegistrar()->ensureVoice('cartesia', HVR_VOICE))->toBe(['status' => 'bound', 'voice_id' => 'bound-voice-2']);

    expect(HeygenVendorSecret::query()->where('engine', 'cartesia')->value('secret_id'))->toBe('fresh-secret')
        ->and(hvrCount('POST', '/voices/third_party'))->toBe(2);
});

test('a concurrent save while a bind is in flight makes no second bind call and never returns a wrong id', function (): void {
    $inner = null;

    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['code' => 1000, 'data' => ['id' => 'sec-1'], 'message' => 'ok'], 200),
        'api.liveavatar.com/v1/voices/third_party' => function () use (&$inner) {
            // The "other request": arrives while the first one is mid-POST.
            $inner = hvrRegistrar()->ensureVoice('cartesia', HVR_VOICE);

            return Http::response(['code' => 1000, 'data' => ['voice_id' => 'bound-voice-1'], 'message' => 'ok'], 200);
        },
    ]);

    $outer = hvrRegistrar()->ensureVoice('cartesia', HVR_VOICE);

    expect($outer)->toBe(['status' => 'bound', 'voice_id' => 'bound-voice-1'])
        ->and($inner)->toBe(['status' => 'failed', 'code' => 'tts_bind_busy'])
        ->and(hvrCount('POST', '/voices/third_party'))->toBe(1)
        ->and(HeygenBoundVoice::query()->count())->toBe(1);

    // And once the first has finished, the next save simply reads the ledger.
    expect(hvrRegistrar()->ensureVoice('cartesia', HVR_VOICE))->toBe($outer)
        ->and(hvrCount('POST', '/voices/third_party'))->toBe(1);
});

test('losing the race at the unique key adopts the winner and removes its own duplicate voice', function (): void {
    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['code' => 1000, 'data' => ['id' => 'sec-1'], 'message' => 'ok'], 200),
        'api.liveavatar.com/v1/voices/third_party' => function () {
            // Another PROCESS (no shared cache lock) committed its row first.
            HeygenBoundVoice::query()->create([
                'engine' => 'cartesia', 'provider_voice_id' => HVR_VOICE, 'secret_id' => 'sec-1', 'voice_id' => 'winner-voice',
            ]);

            return Http::response(['code' => 1000, 'data' => ['voice_id' => 'loser-voice'], 'message' => 'ok'], 200);
        },
        'api.liveavatar.com/v1/voices/*' => Http::response(['code' => 1000, 'data' => null, 'message' => 'Voice deleted successfully'], 200),
    ]);

    expect(hvrRegistrar()->ensureVoice('cartesia', HVR_VOICE))->toBe(['status' => 'bound', 'voice_id' => 'winner-voice']);

    Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && $r->url() === 'https://api.liveavatar.com/v1/voices/loser-voice');
    expect(HeygenBoundVoice::query()->count())->toBe(1);
});

test('the ledger refuses a duplicate (engine, provider_voice_id) and a row without a voice id', function (): void {
    HeygenBoundVoice::query()->create(['engine' => 'cartesia', 'provider_voice_id' => 'v', 'secret_id' => 's', 'voice_id' => 'a']);

    expect(fn () => HeygenBoundVoice::query()->create(['engine' => 'cartesia', 'provider_voice_id' => 'v', 'secret_id' => 's', 'voice_id' => 'b']))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(fn () => HeygenBoundVoice::query()->create(['engine' => 'cartesia', 'provider_voice_id' => 'w', 'secret_id' => 's', 'voice_id' => null]))
        ->toThrow(QueryException::class);
});

test('boundVoiceId reads the ledger only and never calls LiveAvatar', function (): void {
    Http::fake();
    HeygenBoundVoice::query()->create(['engine' => 'cartesia', 'provider_voice_id' => 'v', 'secret_id' => 's', 'voice_id' => 'a']);

    expect(hvrRegistrar()->boundVoiceId('cartesia', 'v'))->toBe('a')
        ->and(hvrRegistrar()->boundVoiceId('cartesia', 'unknown'))->toBeNull()
        ->and(hvrRegistrar()->boundVoiceId('elevenlabs', 'v'))->toBeNull();

    Http::assertNothingSent();
});

test('no key and no response body reaches the logs', function (): void {
    $logged = [];
    Log::listen(function ($event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });

    Http::fake([
        'api.liveavatar.com/v1/secrets' => Http::response(['message' => 'echo CARTESIA_KEY_X'], 500),
    ]);

    hvrRegistrar()->ensureVoice('cartesia', HVR_VOICE);

    expect($logged)->not->toBe([])
        ->and(implode('|', $logged))->not->toContain('CARTESIA_KEY_X')
        ->and(implode('|', $logged))->not->toContain('HEYGEN_KEY_X');
});
