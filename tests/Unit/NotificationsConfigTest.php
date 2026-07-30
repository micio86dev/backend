<?php

declare(strict_types=1);

/**
 * Config invariants for C12 — Notifications & Reminders.
 *
 * Modelled on tests/Unit/C10/WebhooksConfigTest.php. These are not "does the
 * file parse" tests: each assertion pins a value that, if it drifted, would
 * break the capability silently rather than loudly.
 */

use App\Enums\NotificationType;

test('the suppression window is a positive number of seconds', function (): void {
    // A zero or negative window makes every occurrence "outside the window",
    // which turns storm suppression off without removing any code — one
    // provider outage then emails once per candidate.
    expect(config('notifications.suppression.window_seconds'))
        ->toBeInt()
        ->toBeGreaterThan(0);
});

test('per-type suppression overrides are keyed by real notification types', function (): void {
    $overrides = config('notifications.suppression.window_seconds_by_type');
    expect($overrides)->toBeArray();

    $valid = array_map(fn (NotificationType $t): string => $t->value, NotificationType::cases());

    foreach (array_keys($overrides) as $key) {
        // A typo'd key is not an error at runtime — it just never matches, and
        // the type silently falls back to the global window.
        expect($valid)->toContain($key);
    }

    foreach ($overrides as $seconds) {
        expect($seconds)->toBeInt()->toBeGreaterThan(0);
    }
});

test('recipient roles are non-empty and a subset of the seeded authorization roles', function (): void {
    $roles = config('notifications.recipients.roles');

    expect($roles)->toBeArray()->not->toBeEmpty();

    // These are Spatie AUTHORIZATION roles, not BEAI organizational roles
    // (ICO/FLL/MLL/BUL/SRX). Confusing the two is a documented hazard.
    expect(array_diff($roles, ['admin', 'operator', 'viewer']))->toBe([]);
});

test('the recipient guard is pinned explicitly rather than left to the default', function (): void {
    // Both `web` and `api` use the users provider, so an AUTH_GUARD override
    // would silently match zero roles and produce zero recipients — a delivered
    // alert becomes no alert, with no error anywhere.
    expect(config('notifications.recipients.guard'))->toBeString()->not->toBeEmpty();
});

test('dispatch retry settings are bounded and non-zero', function (): void {
    expect(config('notifications.dispatch.tries'))->toBeInt()->toBeGreaterThanOrEqual(1);

    $backoff = config('notifications.dispatch.backoff_seconds');
    expect($backoff)->toBeArray()->not->toBeEmpty();
    foreach ($backoff as $seconds) {
        expect($seconds)->toBeInt()->toBeGreaterThanOrEqual(0);
    }

    expect(config('notifications.dispatch.last_error_max_chars'))->toBeInt()->toBeGreaterThan(0);
});

test('the config declares NO queue key', function (): void {
    // D6 regression guard, and the sharpest test in this file.
    //
    // config/queue.php defaults runtime.worker_queues to ['default']. A
    // `notifications` queue name that no worker consumes would strand every
    // alert silently — the worst possible failure for a capability whose entire
    // job is to tell a human something broke. C10 added a delivery.queue key;
    // C12 deliberately does not. Adding one later MUST land together with the
    // matching worker_queues entry.
    $config = config('notifications');

    expect(data_get($config, 'dispatch.queue'))->toBeNull();
    expect(array_keys($config))->not->toContain('queue');
});
