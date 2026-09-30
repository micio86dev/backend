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

// ---------------------------------------------------------------------------
// composeReusable() — the reusable-interview-links URL (design AD-6)
//
// The token rides in the URL FRAGMENT, so it is never sent to a server, a
// proxy, an access log or a Referer header. Origin and locale-prefix rules are
// the ones `compose()` already owns; only the path shape differs.
//
// REQ: The Link URL Is Composed From The Candidate App Origin With The Token In
//      The Fragment (sdd/reusable-interview-links/spec/reusable-interview-links)
// ---------------------------------------------------------------------------

test('a reusable URL in the default language is unprefixed and carries the token in the fragment', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);

    $url = (new EntryLinkUrlComposer)->composeReusable('beai_rl_tok', 'it');

    expect($url)->toBe('https://interview.example.com/interview/reusable#beai_rl_tok');
});

test('a reusable URL in another supported language carries the locale prefix', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);

    $url = (new EntryLinkUrlComposer)->composeReusable('beai_rl_tok', 'en');

    expect($url)->toBe('https://interview.example.com/en/interview/reusable#beai_rl_tok');
});

test('a reusable URL for an unsupported language falls back unprefixed and logs the same warning', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);
    Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
        return str_contains($message, 'lang') && ($context['lang'] ?? null) === 'es';
    });

    $url = (new EntryLinkUrlComposer)->composeReusable('beai_rl_tok', 'es');

    expect($url)->toBe('https://interview.example.com/interview/reusable#beai_rl_tok');
});

test('a reusable URL with no language is unprefixed', function (): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com']);

    expect((new EntryLinkUrlComposer)->composeReusable('beai_rl_tok', null))
        ->toBe('https://interview.example.com/interview/reusable#beai_rl_tok');
});

test('the reusable token is only ever in the fragment, never the path or the query', function (string $lang): void {
    config(['interview.candidate_app_url' => 'https://interview.example.com/']);
    $token = 'beai_rl_'.str_repeat('A', 43);

    $url = (new EntryLinkUrlComposer)->composeReusable($token, $lang);

    expect(parse_url($url, PHP_URL_FRAGMENT))->toBe($token);
    expect(parse_url($url, PHP_URL_PATH))->not->toContain('beai_rl_');
    expect(parse_url($url, PHP_URL_QUERY))->toBeNull();
})->with(['it', 'en']);

test('a reusable URL fails loud when candidate_app_url is unset and never borrows app.url', function (): void {
    config(['app.url' => 'https://api.example.com']);
    config(['interview.candidate_app_url' => null]);

    expect(fn () => (new EntryLinkUrlComposer)->composeReusable('beai_rl_tok', 'it'))
        ->toThrow(EntryLinkUrlNotConfigured::class);
});

test('a reusable URL fails loud when candidate_app_url is empty', function (): void {
    config(['interview.candidate_app_url' => '']);

    expect(fn () => (new EntryLinkUrlComposer)->composeReusable('beai_rl_tok', 'it'))
        ->toThrow(EntryLinkUrlNotConfigured::class);
});
