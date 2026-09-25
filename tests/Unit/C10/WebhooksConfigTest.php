<?php

declare(strict_types=1);

/**
 * RED — 4.2: config/webhooks.php invariants (C10, design.md D3/D8).
 *
 * The backoff curve has one delay per RETRY (not per attempt): with max_attempts=6,
 * there are 5 retry gaps between attempts 1-6, so backoff_seconds MUST have exactly
 * max_attempts - 1 entries. A drift here would silently change the retry contract.
 */
test('config/webhooks.php exists and is an array', function (): void {
    expect(config('webhooks'))->toBeArray();
});

test('backoff_seconds has exactly max_attempts - 1 entries', function (): void {
    $backoff = config('webhooks.delivery.backoff_seconds');
    $maxAttempts = config('webhooks.delivery.max_attempts');

    expect($backoff)->toBeArray();
    expect($maxAttempts)->toBeInt();
    expect(count($backoff))->toBe(
        $maxAttempts - 1,
        'Expected count(backoff_seconds)='.count($backoff).' to equal max_attempts-1='.($maxAttempts - 1)
    );
});

test('events.types is the closed progress/evaluation set', function (): void {
    expect(config('webhooks.events.types'))->toBe(['progress', 'evaluation']);
});

test('signature.version_prefix is v1', function (): void {
    expect(config('webhooks.signature.version_prefix'))->toBe('v1');
});

test('no value in the delivery block is hardcoded away from env — max_attempts defaults to 6', function (): void {
    expect(config('webhooks.delivery.max_attempts'))->toBe(6);
});

/**
 * `config()->integer()`/`config()->string()` (Illuminate\Config\Repository)
 * THROW `InvalidArgumentException` when the stored value is not already a
 * native `int`/`string` — unlike a plain `(int)`/`(string)` cast, which
 * coerces leniently. `App\Jobs\DeliverWebhookJob::handle()`/`tries()` read
 * `webhooks.delivery.max_attempts`, `webhooks.http.timeout_seconds`,
 * `webhooks.http.connect_timeout_seconds`, `webhooks.errors.
 * max_last_error_chars` via `->integer()` and `webhooks.http.user_agent` via
 * `->string()`. These are safe ONLY because config/webhooks.php itself
 * already applies `(int) env(...)` (or a plain `env()` with a string
 * default, for `user_agent`) at FILE-EVALUATION time — every `.env` value is
 * always a string, but the cast happens before `config()` ever sees it, so
 * the value `config()->integer()`/`->string()` reads back is already the
 * native type regardless of how the underlying env var arrived. This test
 * proves that empirically, not by assumption: it reloads config/webhooks.php
 * directly with an env var set to a numeric STRING (a real `.env` value's
 * actual type) and asserts the returned config array already holds a native
 * `int`, exactly like a fresh boot would produce.
 */
test('config/webhooks.php casts an env numeric string to a native int at file-evaluation time, before config()->integer() ever sees it', function (): void {
    putenv('WEBHOOK_MAX_ATTEMPTS=9');
    $_ENV['WEBHOOK_MAX_ATTEMPTS'] = '9';
    $_SERVER['WEBHOOK_MAX_ATTEMPTS'] = '9';

    try {
        /** @var array<string, mixed> $reloaded */
        $reloaded = require config_path('webhooks.php');

        expect($reloaded['delivery']['max_attempts'])->toBeInt()->toBe(9);
    } finally {
        putenv('WEBHOOK_MAX_ATTEMPTS');
        unset($_ENV['WEBHOOK_MAX_ATTEMPTS'], $_SERVER['WEBHOOK_MAX_ATTEMPTS']);
    }
});

/**
 * Every key `DeliverWebhookJob` reads via `config()->integer()`/`->string()`
 * is asserted here, against the actual booted config (never a hand-built
 * array), to be the native type those methods require — the regression this
 * test would have caught: a future change to config/webhooks.php that
 * starts passing an env value through un-cast (e.g. dropping the `(int)` in
 * front of an `env()` call) would flip one of these to a `string` and this
 * test would fail immediately, before `DeliverWebhookJob` ever threw in
 * production.
 */
test('every webhooks config key DeliverWebhookJob reads via config()->integer()/->string() is already that native type', function (): void {
    expect(config()->integer('webhooks.delivery.max_attempts'))->toBeInt();
    expect(config()->integer('webhooks.http.timeout_seconds'))->toBeInt();
    expect(config()->integer('webhooks.http.connect_timeout_seconds'))->toBeInt();
    expect(config()->integer('webhooks.errors.max_last_error_chars'))->toBeInt();
    expect(config()->string('webhooks.http.user_agent'))->toBeString();
});
