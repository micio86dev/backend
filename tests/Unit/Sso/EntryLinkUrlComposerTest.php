<?php

declare(strict_types=1);

/**
 * RED — EntryLinkUrlComposer (operator-interview-link, design D3).
 *
 * Origin + locale-prefix composition, fail-loud on missing configuration.
 * Pure/config-driven — no HTTP, no participant/project rows.
 *
 * REQ: Entry URL Locale Prefixing Is Owned by the Minter,
 *      CANDIDATE_APP_URL Fails Loud When Unset
 *      (openspec/changes/operator-interview-link/specs/participant-sso/spec.md)
 */

use App\Exceptions\Sso\EntryLinkUrlNotConfigured;
use App\Support\Sso\EntryLinkUrlComposer;
use Illuminate\Support\Facades\Log;

test('resolved language it composes an unprefixed URL', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);

    $url = (new EntryLinkUrlComposer)->compose('tok123', 'it');

    expect($url)->toBe('https://interview.example.com/interview/tok123');
});

test('resolved language en composes a locale-prefixed URL', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);

    $url = (new EntryLinkUrlComposer)->compose('tok123', 'en');

    expect($url)->toBe('https://interview.example.com/en/interview/tok123');
});

test('an unsupported locale falls back unprefixed and logs a warning', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
        return str_contains($message, 'lang') && ($context['lang'] ?? null) === 'es';
    });

    $url = (new EntryLinkUrlComposer)->compose('tok123', 'es');

    expect($url)->toBe('https://interview.example.com/interview/tok123');
});

test('a trailing slash on the configured origin is normalised away', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com/']);

    $url = (new EntryLinkUrlComposer)->compose('tok123', 'it');

    expect($url)->toBe('https://interview.example.com/interview/tok123');
});

test('an unset candidate_app_url fails loud, never silently', function (): void {
    config(['interview.candidate_app_url' => null]);

    expect(fn () => (new EntryLinkUrlComposer)->compose('tok123', 'it'))
        ->toThrow(EntryLinkUrlNotConfigured::class);
});

test('the composed URL never derives from config(app.url) when candidate_app_url is unset', function (): void {
    config(['app.url' => 'https://api.example.com']);
    config(['interview.candidate_app_url' => null]);

    try {
        (new EntryLinkUrlComposer)->compose('tok123', 'it');
        expect(false)->toBeTrue('Expected EntryLinkUrlNotConfigured to be thrown.');
    } catch (EntryLinkUrlNotConfigured $e) {
        expect($e->getMessage())->not->toContain('api.example.com');
    }
});
