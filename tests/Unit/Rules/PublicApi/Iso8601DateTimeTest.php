<?php

declare(strict_types=1);

/**
 * `App\Rules\PublicApi\Iso8601DateTime` — step 6 review follow-up, Part A
 * item 3: `!`-reset formats (no field silently defaults to "now"), a
 * trailing `Z` parses as UTC (not the ambient default timezone), and a
 * rolled-over calendar date (`2026-02-31`) is rejected rather than silently
 * normalized to March.
 *
 * Registered in `tests/Pest.php` under `Unit/Rules/PublicApi` with
 * `TestCase` (no `RefreshDatabase`) — the rule and Carbon are DB-free, but
 * `the validation rule ...` tests below go through the `Validator` facade,
 * which needs the booted app's container.
 */

use App\Rules\PublicApi\Iso8601DateTime;
use Illuminate\Support\Facades\Validator;

test('parse() rejects a calendar date that rolls over (2026-02-31), never silently normalizing it to March', function (): void {
    expect(Iso8601DateTime::parse('2026-02-31T00:00:00Z'))->toBeNull();
});

test('parse() rejects a rolled-over date on the offset form too (2026-04-31)', function (): void {
    expect(Iso8601DateTime::parse('2026-04-31T00:00:00+02:00'))->toBeNull();
});

test('parse() accepts a genuine end-of-month date on the Z form', function (): void {
    $parsed = Iso8601DateTime::parse('2026-01-31T23:59:59Z');

    expect($parsed)->not->toBeNull();
    expect($parsed->format('Y-m-d'))->toBe('2026-01-31');
});

test('parse() resolves a trailing Z as UTC, not the ambient default timezone', function (): void {
    // Captured, not hardcoded — restoring a literal 'UTC' would silently
    // clobber whatever zone was actually active before this test mutated
    // it, on any environment/suite ordering where that is not already
    // 'UTC'.
    $originalTimezone = date_default_timezone_get();
    date_default_timezone_set('America/New_York');

    try {
        $parsed = Iso8601DateTime::parse('2026-01-01T00:00:00Z');

        expect($parsed)->not->toBeNull();
        expect($parsed->getTimezone()->getName())->toBe('UTC');
        expect($parsed->toIso8601String())->toBe('2026-01-01T00:00:00+00:00');
    } finally {
        date_default_timezone_set($originalTimezone);
    }
});

test('parse() converts a numeric-offset value to the correct UTC instant', function (): void {
    $parsed = Iso8601DateTime::parse('2026-01-02T00:00:00+02:00');

    expect($parsed)->not->toBeNull();
    expect($parsed->utc()->toIso8601String())->toBe('2026-01-01T22:00:00+00:00');
});

test('the validation rule fails on a rolled-over calendar date', function (): void {
    $validator = Validator::make(['at' => '2026-02-31T00:00:00Z'], ['at' => [new Iso8601DateTime]]);

    expect($validator->fails())->toBeTrue();
});

test('the validation rule passes on a genuine strict ISO 8601 value', function (): void {
    $validator = Validator::make(['at' => '2026-01-01T00:00:00Z'], ['at' => [new Iso8601DateTime]]);

    expect($validator->fails())->toBeFalse();
});

// ─── gga finding 4: 1-5 digit fractional seconds must round-trip ────────────

test('parse() accepts a single fractional digit (.1Z), like a hand-typed millisecond truncation', function (): void {
    $parsed = Iso8601DateTime::parse('2026-01-01T00:00:00.1Z');

    expect($parsed)->not->toBeNull();
    expect($parsed->micro)->toBe(100000);
});

test('parse() accepts JavaScript\'s three-digit millisecond form (.123Z)', function (): void {
    $parsed = Iso8601DateTime::parse('2026-01-01T00:00:00.123Z');

    expect($parsed)->not->toBeNull();
    expect($parsed->micro)->toBe(123000);
});

test('parse() accepts a three-digit millisecond form on the numeric-offset shape (.123+02:00)', function (): void {
    $parsed = Iso8601DateTime::parse('2026-01-01T00:00:00.123+02:00');

    expect($parsed)->not->toBeNull();
    expect($parsed->micro)->toBe(123000);
});

test('parse() still accepts the full six-digit microsecond form', function (): void {
    $parsed = Iso8601DateTime::parse('2026-01-01T00:00:00.123456Z');

    expect($parsed)->not->toBeNull();
    expect($parsed->micro)->toBe(123456);
});

test('parse() still rejects a rolled-over date carrying fractional seconds (2026-02-31.123Z)', function (): void {
    expect(Iso8601DateTime::parse('2026-02-31T00:00:00.123Z'))->toBeNull();
});

// ─── step 6 review follow-up, finding 4: negative-zero and colon-less offsets ──

test('parse() accepts a negative-zero offset (-00:00) as equivalent to +00:00 (same instant)', function (): void {
    $parsed = Iso8601DateTime::parse('2026-01-01T00:00:00-00:00');

    expect($parsed)->not->toBeNull();
    expect($parsed->utc()->toIso8601String())->toBe('2026-01-01T00:00:00+00:00');
});

test('parse() accepts a colon-less numeric offset (+0100)', function (): void {
    $parsed = Iso8601DateTime::parse('2026-01-01T00:00:00+0100');

    expect($parsed)->not->toBeNull();
    expect($parsed->utc()->toIso8601String())->toBe('2025-12-31T23:00:00+00:00');
});

test('parse() accepts a colon-less negative-zero offset (-0000) as equivalent to +0000', function (): void {
    $parsed = Iso8601DateTime::parse('2026-01-01T00:00:00-0000');

    expect($parsed)->not->toBeNull();
    expect($parsed->utc()->toIso8601String())->toBe('2026-01-01T00:00:00+00:00');
});

test('parse() accepts a colon-less numeric offset with fractional seconds (.123+0200)', function (): void {
    $parsed = Iso8601DateTime::parse('2026-01-01T00:00:00.123+0200');

    expect($parsed)->not->toBeNull();
    expect($parsed->micro)->toBe(123000);
    expect($parsed->utc()->toIso8601String())->toBe('2025-12-31T22:00:00+00:00');
});

test('parse() still rejects a rolled-over date on the colon-less offset form (2026-04-31+0200)', function (): void {
    expect(Iso8601DateTime::parse('2026-04-31T00:00:00+0200'))->toBeNull();
});

test('parse() still rejects a genuine non-zero offset that does not round-trip byte for byte', function (): void {
    // A real, non-zero offset is never normalized — only the "-00:00 means
    // the same instant as +00:00" case is. Confirms the equivalence fix
    // does not widen into accepting arbitrary offset drift.
    expect(Iso8601DateTime::parse('2026-01-01T00:00:00+99:99'))->toBeNull();
});
