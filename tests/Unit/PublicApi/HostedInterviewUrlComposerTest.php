<?php

declare(strict_types=1);

/**
 * `App\Support\PublicApi\HostedInterviewUrlComposer` — public-api step 5,
 * SPEC.md §3.5 "Hosted URL", G-33.
 */

use App\Support\PublicApi\HostedInterviewUrlComposer;

test('composes an unprefixed URL for the default locale, from public_api.interview_url', function (): void {
    config(['public_api.interview_url' => 'https://interview.beai.example', 'interview.frontend_default_locale' => 'it', 'interview.frontend_locales' => ['it', 'en']]);

    $url = (new HostedInterviewUrlComposer)->compose('a-token', 'it');

    expect($url)->toBe('https://interview.beai.example/i/a-token');
});

test('composes a locale-prefixed URL for a non-default, supported locale', function (): void {
    config(['public_api.interview_url' => 'https://interview.beai.example', 'interview.frontend_default_locale' => 'it', 'interview.frontend_locales' => ['it', 'en']]);

    $url = (new HostedInterviewUrlComposer)->compose('a-token', 'en');

    expect($url)->toBe('https://interview.beai.example/en/i/a-token');
});

test('falls back to interview.candidate_app_url when public_api.interview_url is unset', function (): void {
    config(['public_api.interview_url' => null, 'interview.candidate_app_url' => 'https://interview.example.com/']);

    $url = (new HostedInterviewUrlComposer)->compose('a-token', null);

    expect($url)->toBe('https://interview.example.com/i/a-token');
});

test('throws when neither URL is configured, naming INTERVIEW_URL (gga finding 2)', function (): void {
    config(['public_api.interview_url' => null, 'interview.candidate_app_url' => null]);

    expect(fn () => (new HostedInterviewUrlComposer)->compose('a-token', null))
        ->toThrow(RuntimeException::class, 'HostedInterviewUrlComposer: neither INTERVIEW_URL nor CANDIDATE_APP_URL is configured.');
});
