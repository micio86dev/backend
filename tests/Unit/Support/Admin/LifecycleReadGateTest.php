<?php

declare(strict_types=1);

/**
 * RED → GREEN — LifecycleReadGate acceptance matrix (C11, task 2.1).
 *
 * Fail-closed: any status not an EXPLICIT match denies, for every scope —
 * including the synthetic unknown status against Summary, where the design
 * (D2) argues "no lifecycle threshold" still requires the status to be one
 * of the known domain values, not an arbitrary/corrupt string.
 *
 * "Should pass" cases call assert() directly (no closure) so an unexpected
 * throw aborts the test as a genuine failure — combined with an explicit
 * assertion on the (void) return, this avoids a Pest quirk where
 * `->not->toThrow(Throwable::class)` does not reliably detect a thrown
 * exception when the expected class is the bare Throwable interface.
 *
 * REQ: Lifecycle Read-Gate (openspec/changes/admin-dashboards/specs/admin-read-api/spec.md)
 */

use App\Exceptions\Admin\LifecycleNotReadyException;
use App\Support\Admin\LifecycleReadGate;
use App\Support\Admin\ParticipantReadScope;

// ── Summary — RBAC only, always passes for the 5 known lifecycle statuses ──

test('Summary scope always passes for every known lifecycle status', function (string $status): void {
    $gate = new LifecycleReadGate;

    expect($gate->assert($status, ParticipantReadScope::Summary))->toBeNull();
})->with([
    'in_attesa',
    'in_corso',
    'in_valutazione',
    'completato',
    'errore',
]);

// ── Transcript — requires lifecycle >= in_valutazione ──

test('Transcript scope enforces the in_valutazione threshold', function (string $status, bool $shouldPass): void {
    $gate = new LifecycleReadGate;

    if ($shouldPass) {
        expect($gate->assert($status, ParticipantReadScope::Transcript))->toBeNull();

        return;
    }

    expect(fn () => $gate->assert($status, ParticipantReadScope::Transcript))
        ->toThrow(LifecycleNotReadyException::class);
})->with([
    'in_attesa denies' => ['in_attesa', false],
    'in_corso denies' => ['in_corso', false],
    'in_valutazione passes' => ['in_valutazione', true],
    'completato passes' => ['completato', true],
    'errore denies' => ['errore', false],
]);

// ── Evaluation — requires lifecycle === completato ──

test('Evaluation scope enforces the completato threshold', function (string $status, bool $shouldPass): void {
    $gate = new LifecycleReadGate;

    if ($shouldPass) {
        expect($gate->assert($status, ParticipantReadScope::Evaluation))->toBeNull();

        return;
    }

    expect(fn () => $gate->assert($status, ParticipantReadScope::Evaluation))
        ->toThrow(LifecycleNotReadyException::class);
})->with([
    'in_attesa denies' => ['in_attesa', false],
    'in_corso denies' => ['in_corso', false],
    'in_valutazione denies' => ['in_valutazione', false],
    'completato passes' => ['completato', true],
    'errore denies' => ['errore', false],
]);

// ── Fail-closed: a synthetic unrecognized status denies for EVERY scope ──

test('an unrecognized status denies for every scope, never 200 by accident', function (ParticipantReadScope $scope): void {
    $gate = new LifecycleReadGate;

    expect(fn () => $gate->assert('quantum_superposition', $scope))
        ->toThrow(LifecycleNotReadyException::class);
})->with([
    'Summary' => [ParticipantReadScope::Summary],
    'Transcript' => [ParticipantReadScope::Transcript],
    'Evaluation' => [ParticipantReadScope::Evaluation],
]);

// ── LifecycleNotReadyException carries the machine-readable D4 fields ──

test('LifecycleNotReadyException carries resource, current_status, and required_status', function (): void {
    $gate = new LifecycleReadGate;

    try {
        $gate->assert('in_corso', ParticipantReadScope::Transcript);
        $this->fail('Expected LifecycleNotReadyException to be thrown.');
    } catch (LifecycleNotReadyException $e) {
        expect($e->resource)->toBe('transcript');
        expect($e->currentStatus)->toBe('in_corso');
        expect($e->requiredStatus)->toBe('in_valutazione');
    }
});
