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
