<?php

declare(strict_types=1);

/**
 * Cartesia and ElevenLabs as voice providers in the EXISTING catalogue.
 *
 * Every call is faked: both are real, billed vendor accounts.
 *
 * The response fixtures below follow each vendor's published list-voices shape
 * (Cartesia `GET /voices`, ElevenLabs `GET /v2/voices`). They were NOT
 * captured from a live account in this session.
 */

use App\Support\AvatarTemplates\AvatarProviderCatalogue;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'services.cartesia.api_key' => 'SECRET_CARTESIA_KEY_1',
        'services.elevenlabs.api_key' => 'SECRET_ELEVENLABS_KEY_2',
    ]);
});

test('Cartesia voices expose provider, name, language/locale and the provider voice id', function (): void {
    Http::fake([
        'api.cartesia.ai/voices*' => Http::response([
            'data' => [
                ['id' => 'c-en', 'name' => 'Barbershop Man', 'language' => 'en', 'gender' => 'masculine', 'description' => 'x'],
                ['id' => 'c-it', 'name' => 'Giulia', 'language' => 'it', 'gender' => 'feminine'],
            ],
            'has_more' => false,
        ], 200),
    ]);

    $result = AvatarProviderCatalogue::fetch('cartesia', 'voice');

    expect($result['status'])->toBe('ok');

    // Italian first, then the rest by name.
    expect(array_column($result['items'], 'id'))->toBe(['c-it', 'c-en']);

    expect($result['items'][0])->toMatchArray([
        'id' => 'c-it',
        'provider' => 'cartesia',
        'name' => 'Giulia',
        'label' => 'Giulia',
        'language' => 'it',
        'locale' => 'it',
        'accent' => null,
        'italian' => 'native',
    ]);
    expect($result['items'][1]['italian'])->toBeNull();

    Http::assertSent(fn ($r): bool => $r->hasHeader('X-API-Key', 'SECRET_CARTESIA_KEY_1')
        && $r->hasHeader('Cartesia-Version'));
});

test('Cartesia pagination is followed until has_more is false', function (): void {
    Http::fake([
        'api.cartesia.ai/voices*' => Http::sequence()
            ->push(['data' => [['id' => 'a', 'name' => 'A', 'language' => 'en']], 'has_more' => true, 'next_page' => 'a'], 200)
            ->push(['data' => [['id' => 'b', 'name' => 'B', 'language' => 'en']], 'has_more' => false], 200),
    ]);

    $result = AvatarProviderCatalogue::fetch('cartesia', 'voice');

    expect(array_column($result['items'], 'id'))->toBe(['a', 'b']);
    Http::assertSentCount(2);
});

test('ElevenLabs voices expose accent, locale, preview and separate native Italian from multilingual', function (): void {
    Http::fake([
        'api.elevenlabs.io/v2/voices*' => Http::response([
            'voices' => [
                [
                    'voice_id' => 'e-multi', 'name' => 'Aria', 'preview_url' => 'https://cdn.example/aria.mp3',
                    'labels' => ['accent' => 'american', 'gender' => 'female'],
                    'verified_languages' => [
                        ['language' => 'en', 'locale' => 'en-US', 'accent' => 'american'],
                        ['language' => 'it', 'locale' => 'it-IT', 'accent' => 'american'],
                    ],
                ],
                [
                    'voice_id' => 'e-accent', 'name' => 'Bruno', 'preview_url' => null,
                    'labels' => ['accent' => 'Italian', 'language' => 'en'],
                    'verified_languages' => [],
                ],
                [
                    'voice_id' => 'e-native', 'name' => 'Chiara', 'preview_url' => 'https://cdn.example/chiara.mp3',
                    'labels' => ['gender' => 'female'],
                    'verified_languages' => [
                        ['language' => 'it', 'locale' => 'it-IT', 'accent' => 'standard', 'preview_url' => 'https://cdn.example/chiara-it.mp3'],
                    ],
                ],
                ['voice_id' => 'e-plain', 'name' => 'Aaron', 'labels' => ['accent' => 'british', 'language' => 'en']],
            ],
            'has_more' => false,
            'total_count' => 4,
        ], 200),
    ]);

    $result = AvatarProviderCatalogue::fetch('elevenlabs', 'voice');
    $byId = collect($result['items'])->keyBy('id');

    // native Italian first (by name), then multilingual, then the rest.
    expect(array_column($result['items'], 'id'))->toBe(['e-accent', 'e-native', 'e-multi', 'e-plain']);

    expect($byId['e-native'])->toMatchArray([
        'provider' => 'elevenlabs',
        'name' => 'Chiara',
        'language' => 'it',
        'locale' => 'it-IT',
        'accent' => 'standard',
        'italian' => 'native',
        'preview_audio_url' => 'https://cdn.example/chiara-it.mp3',
    ]);
    expect($byId['e-accent']['italian'])->toBe('native')
        ->and($byId['e-accent']['accent'])->toBe('Italian');
    // Multilingual: can speak Italian, but its own primary language is English.
    expect($byId['e-multi']['italian'])->toBe('multilingual')
        ->and($byId['e-multi']['language'])->toBe('en')
        ->and($byId['e-multi']['preview_audio_url'])->toBe('https://cdn.example/aria.mp3');
    expect($byId['e-plain']['italian'])->toBeNull();

    Http::assertSent(fn ($r): bool => $r->hasHeader('xi-api-key', 'SECRET_ELEVENLABS_KEY_2'));
});

test('ElevenLabs pagination follows next_page_token', function (): void {
    Http::fake([
        'api.elevenlabs.io/v2/voices*' => Http::sequence()
            ->push(['voices' => [['voice_id' => 'a', 'name' => 'A']], 'has_more' => true, 'next_page_token' => 'tok'], 200)
            ->push(['voices' => [['voice_id' => 'b', 'name' => 'B']], 'has_more' => false], 200),
    ]);

    expect(array_column(AvatarProviderCatalogue::fetch('elevenlabs', 'voice')['items'], 'id'))->toBe(['a', 'b']);
    Http::assertSent(fn ($r): bool => str_contains($r->url(), 'next_page_token=tok'));
});

test('a missing key degrades to unavailable without calling the vendor', function (string $provider): void {
    config(['services.cartesia.api_key' => '', 'services.elevenlabs.api_key' => null]);
    Http::fake();

    expect(AvatarProviderCatalogue::fetch($provider, 'voice'))->toBe(['status' => 'unavailable', 'items' => []]);
    Http::assertNothingSent();
})->with(['cartesia', 'elevenlabs']);

test('a vendor failure never leaks the key', function (string $provider): void {
    Http::fake(['*' => Http::response(['detail' => 'bad key SECRET_CARTESIA_KEY_1 SECRET_ELEVENLABS_KEY_2'], 401)]);

    $result = AvatarProviderCatalogue::fetch($provider, 'voice');

    expect($result)->toBe(['status' => 'unavailable', 'items' => []]);
})->with(['cartesia', 'elevenlabs']);
