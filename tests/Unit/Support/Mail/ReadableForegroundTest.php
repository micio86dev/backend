<?php

declare(strict_types=1);

/**
 * Parity with the backoffice's `readableForeground` (`app/utils/brand-color.ts`)
 * and its spec: the same fixtures must give the same answers, so a tenant sees
 * the same text colour on a button in the app and in the email.
 */

use App\Support\Mail\ReadableForeground;

test('it picks white for a dark colour', function (): void {
    expect(ReadableForeground::for('#771aaf'))->toBe('#ffffff');
});

test('it picks black for a light colour', function (): void {
    expect(ReadableForeground::for('#ffd400'))->toBe('#000000');
});

test('it handles the boundary cases', function (): void {
    expect(ReadableForeground::for('#ffffff'))->toBe('#000000')
        ->and(ReadableForeground::for('#000000'))->toBe('#ffffff');
});

test('it is case-insensitive on the hex digits', function (): void {
    expect(ReadableForeground::for('#FFD400'))->toBe('#000000');
});

test('it falls back to white for a value it cannot parse', function (): void {
    expect(ReadableForeground::for('not-a-color'))->toBe('#ffffff')
        ->and(ReadableForeground::for('#fff'))->toBe('#ffffff');
});

test('the contrast ratio gives the known extremes', function (): void {
    expect(ReadableForeground::contrastRatio('#ffffff', '#000000'))->toEqualWithDelta(21.0, 0.05)
        ->and(ReadableForeground::contrastRatio('#771aaf', '#771aaf'))->toEqualWithDelta(1.0, 0.00001);
});
