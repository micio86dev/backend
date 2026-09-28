<?php

declare(strict_types=1);

/**
 * RED — webhook-ssrf-guard design.md D2: SafeWebhookUrl.
 *
 * Calls `validate()` directly with a capturing closure rather than round-tripping
 * through the `Validator` facade — this directory is not wired to boot the app
 * container (unlike `Unit/Rules/PublicApi`, `tests/Pest.php:562-566`), and the rule
 * under test has no dependency on anything the container would provide.
 */

use App\Rules\SafeWebhookUrl;

test('a URL blocked by OutboundHostGuard fails with the webhook_url_unsafe slug', function (): void {
    $failMessage = null;

    (new SafeWebhookUrl)->validate('webhook_url', 'https://127.0.0.1/hook', function (string $message) use (&$failMessage): void {
        $failMessage = $message;
    });

    expect($failMessage)->toBe('webhook_url_unsafe');
});

test('a URL allowed by OutboundHostGuard passes', function (): void {
    $failed = false;

    (new SafeWebhookUrl)->validate('webhook_url', 'https://example.test/hook', function () use (&$failed): void {
        $failed = true;
    });

    expect($failed)->toBeFalse();
});

test('a non-string value is not this rule\'s concern (leaves it to the string rule)', function (): void {
    $failed = false;

    (new SafeWebhookUrl)->validate('webhook_url', 123, function () use (&$failed): void {
        $failed = true;
    });

    expect($failed)->toBeFalse();
});
