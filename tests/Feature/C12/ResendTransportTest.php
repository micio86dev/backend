<?php

declare(strict_types=1);

/**
 * Production mail transport — Resend (C12, ratified 2026-07-30).
 *
 * Nothing here touches the network. `phpunit.xml` pins MAIL_MAILER=array and
 * that stays: a test that actually sent mail would be a test that fails when
 * someone else's API has a bad day.
 *
 * What these assert is the WIRING, which is the part that breaks silently. A
 * missing package or an unset key does not announce itself at boot — it
 * surfaces as a transport exception inside a queued job, i.e. as a `failed`
 * notification row long after deploy.
 */

use Illuminate\Support\Facades\Mail;

test('the resend mailer is declared and uses the resend transport', function (): void {
    expect(config('mail.mailers.resend.transport'))->toBe('resend');
});

test('the framework can build the resend transport', function (): void {
    // Laravel 13 ships ResendTransport first-party, but it type-hints
    // Resend\Contracts\Client — so without the resend/resend-php package this
    // throws rather than degrading. Building the mailer is what proves the
    // package is actually installed and autoloadable.
    config()->set('services.resend.key', 're_test_key_not_used_for_sending');

    $mailer = Mail::mailer('resend');

    expect($mailer)->not->toBeNull();
});

test('the api key is read from config, never hardcoded', function (): void {
    // The transport falls back to services.resend.key when the mailer config
    // carries no explicit key. A credential belongs in the platform's variable
    // store; this asserts the indirection exists so nobody is tempted to inline
    // one.
    $source = (string) file_get_contents(config_path('services.php'));

    expect($source)->toContain("'resend'");
    expect($source)->toContain("env('RESEND_API_KEY')");

    // A real Resend key starts with `re_`. None may appear in tracked config.
    expect($source)->not->toMatch('/[\'"]re_[A-Za-z0-9]{10,}/');
});

test('tests never send through a real transport', function (): void {
    // phpunit.xml pins this. If it ever drifts, the suite starts making
    // outbound calls on someone's account.
    expect(config('mail.default'))->toBe('array');
});
