<?php

declare(strict_types=1);

/**
 * Unit tests for RoleNoBarsException (C9 D6 — WARNING-3 quality debt closure).
 *
 * Verifies:
 * (a) Constructor accepts a competency code and builds a well-formed message.
 * (b) The exception extends \RuntimeException.
 * (c) Message contains the competency code (unambiguous diagnostic).
 * (d) The exception is throwable and catchable as \RuntimeException.
 *
 * REQ: RoleNoBarsException direct coverage (C9 D6)
 */

use App\Exceptions\Scoring\RoleNoBarsException;

test('(a) RoleNoBarsException message contains the competency code', function (): void {
    $e = new RoleNoBarsException('COL');

    expect($e->getMessage())->toContain('COL');
});

test('(b) RoleNoBarsException extends RuntimeException', function (): void {
    $e = new RoleNoBarsException('SLF');

    expect($e)->toBeInstanceOf(RuntimeException::class);
});

test('(c) RoleNoBarsException message is human-readable (non-empty)', function (): void {
    $e = new RoleNoBarsException('COM');

    expect($e->getMessage())->not->toBeEmpty();
});

test('(d) RoleNoBarsException is throwable and catchable as RuntimeException', function (): void {
    $caught = false;

    try {
        throw new RoleNoBarsException('JDG');
    } catch (RuntimeException $e) {
        $caught = true;
        expect($e)->toBeInstanceOf(RoleNoBarsException::class);
        expect($e->getMessage())->toContain('JDG');
    }

    expect($caught)->toBeTrue('RoleNoBarsException must be catchable as RuntimeException.');
});

test('(e) RoleNoBarsException message format matches expected pattern', function (): void {
    $e = new RoleNoBarsException('DRV');

    expect($e->getMessage())->toBe('No BARS indicators found for competency [DRV].');
});
