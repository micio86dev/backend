<?php

declare(strict_types=1);

/**
 * `ProviderPreflight` turns a misconfigured provider into a machine-coded 422 before a
 * session exists. It must report exactly what is missing, accept the platform default as a
 * floor under a template, and never be the thing that breaks an interview.
 */

use App\Services\Provider\ProviderPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'interview.preflight.verify_references' => false,
        'interview.tavus.api_key' => 'tvs-key',
        'interview.tavus.replica_id' => 'replica-1',
        'interview.tavus.persona_id' => null,
        'interview.heygen.api_key' => 'hg-key',
        'interview.heygen.avatar_id' => 'avatar-1',
        'interview.heygen.voice_id' => 'voice-1',
    ]);
});

/** @return list<array{key: string, code: string}> */
function preflight(string $provider): array
{
    return app(ProviderPreflight::class)->check($provider, null);
}

test('a provider with no external dependency, such as the test-mode mock, always passes', function (): void {
    config(['interview.tavus.api_key' => '', 'interview.heygen.api_key' => '']);

    expect(preflight('mock'))->toBe([]);
});

test('a fully configured provider passes on the platform defaults alone', function (string $provider): void {
    expect(preflight($provider))->toBe([]);
})->with(['tavus', 'heygen']);

test('Tavus reports a missing key and a missing face separately', function (): void {
    config(['interview.tavus.api_key' => ' ', 'interview.tavus.replica_id' => null, 'interview.tavus.persona_id' => null]);

    expect(preflight('tavus'))->toBe([
        ['key' => 'credentials', 'code' => 'provider_key_missing'],
        ['key' => 'faceId', 'code' => 'avatar_missing'],
    ]);
});

test('Tavus accepts a persona as the identity when there is no replica', function (): void {
    config(['interview.tavus.replica_id' => null, 'interview.tavus.persona_id' => 'persona-1']);

    expect(preflight('tavus'))->toBe([]);
});

test('HeyGen reports key, avatar and voice independently', function (): void {
    config(['interview.heygen.api_key' => '', 'interview.heygen.avatar_id' => null, 'interview.heygen.voice_id' => null]);

    expect(preflight('heygen'))->toBe([
        ['key' => 'credentials', 'code' => 'provider_key_missing'],
        ['key' => 'avatarId', 'code' => 'avatar_missing'],
        ['key' => 'voiceId', 'code' => 'voice_missing'],
    ]);
});

test('a refusal is logged server-side with the reasons but no provider content', function (): void {
    Log::spy();
    config(['interview.heygen.api_key' => '']);

    preflight('heygen');

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => str_contains($message, 'pre-flight refused')
            && $context['provider'] === 'heygen'
            && $context['reasons'] === [['key' => 'credentials', 'code' => 'provider_key_missing']],
    );
});

test('an unreadable template never blocks an interview the provider defaults could start', function (): void {
    DB::beforeExecuting(function (string $query): void {
        if (str_contains($query, 'avatar_templates')) {
            throw new RuntimeException('template store unavailable');
        }
    });

    expect(preflight('heygen'))->toBe([])
        ->and(preflight('tavus'))->toBe([]);
});
