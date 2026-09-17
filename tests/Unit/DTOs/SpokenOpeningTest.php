<?php

declare(strict_types=1);

use App\DTOs\Conversation\SpokenOpening;

test('primary() refuses a number below 1', function (): void {
    expect(fn () => SpokenOpening::primary(0))->toThrow(InvalidArgumentException::class);
});

test('resumed() refuses an empty primary set', function (): void {
    expect(fn () => SpokenOpening::resumed(0, 0))->toThrow(InvalidArgumentException::class);
});

test('resumed() points at the pending primary, or the last one once all were asked', function (): void {
    $pending = SpokenOpening::resumed(1, 3);
    $allAsked = SpokenOpening::resumed(5, 3);

    expect($pending->primaryNumber)->toBe(2)
        ->and($pending->isReAskOfAskedPrimary())->toBeFalse()
        ->and($allAsked->primaryNumber)->toBe(3)
        ->and($allAsked->primariesAskedBefore)->toBe(3)
        ->and($allAsked->isReAskOfAskedPrimary())->toBeTrue();
});

test('fallback() names no primary', function (): void {
    $opening = SpokenOpening::fallback(resumed: true);

    expect($opening->primaryNumber)->toBeNull()
        ->and($opening->resumed)->toBeTrue()
        ->and($opening->isReAskOfAskedPrimary())->toBeFalse();
});
