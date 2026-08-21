<?php

declare(strict_types=1);

/**
 * RED → GREEN — LifecycleReadGate acceptance matrix (C11, task 2.1; loosened
 * for operator-participant-visibility PR1, D1/D2/D3).
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
 * D1's two-clause rule (`SCOPE_RULES = ['minimum' => ..., 'off_progression'
 * => [...]]`) rests on three invariants, each pinned below as its own test
 * rather than as a comment: disjointness (an off_progression member can
 * never also be a ranked ORDERED_STATUSES member — that is what makes the
 * ranked code path structurally unreachable for it), closure (every
 * off_progression member is a KNOWN_STATUSES value — a typo must not admit
 * an unrecognized status), and Evaluation's off_progression list being
 * empty (the mechanism that opens `errore` for Transcript must be visibly
 * refused for Evaluation).
 *
 * REQ: Lifecycle Read-Gate (openspec/changes/operator-participant-visibility/specs/admin-read-api/spec.md)
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

// ── Transcript — requires lifecycle >= in_corso, OR errore (off-progression) ──

test('Transcript scope enforces the in_corso threshold, plus the off-progression errore allowance', function (string $status, bool $shouldPass): void {
    $gate = new LifecycleReadGate;

    if ($shouldPass) {
        expect($gate->assert($status, ParticipantReadScope::Transcript))->toBeNull();

        return;
    }

    expect(fn () => $gate->assert($status, ParticipantReadScope::Transcript))
        ->toThrow(LifecycleNotReadyException::class);
})->with([
    'in_attesa denies' => ['in_attesa', false],
    'in_corso passes' => ['in_corso', true],
    'in_valutazione passes' => ['in_valutazione', true],
    'completato passes' => ['completato', true],
    'errore passes (off-progression)' => ['errore', true],
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
        // in_attesa is the only status still denied for Transcript post-D1 —
        // required_status now truthfully reports the NEW minimum, 'in_corso'.
        $gate->assert('in_attesa', ParticipantReadScope::Transcript);
        $this->fail('Expected LifecycleNotReadyException to be thrown.');
    } catch (LifecycleNotReadyException $e) {
        expect($e->resource)->toBe('transcript');
        expect($e->currentStatus)->toBe('in_attesa');
        expect($e->requiredStatus)->toBe('in_corso');
    }
});

// ── D1 invariants — the safety property the two-clause rule rests on ──

/**
 * @return array{ORDERED_STATUSES: list<string>, KNOWN_STATUSES: list<string>, SCOPE_RULES: array<string, array{minimum: string, off_progression: list<string>}>}
 */
function lifecycleReadGateConstants(): array
{
    $reflection = new ReflectionClass(LifecycleReadGate::class);

    return [
        'ORDERED_STATUSES' => $reflection->getConstant('ORDERED_STATUSES'),
        'KNOWN_STATUSES' => $reflection->getConstant('KNOWN_STATUSES'),
        'SCOPE_RULES' => $reflection->getConstant('SCOPE_RULES'),
    ];
}

test('ORDERED_STATUSES is byte-for-byte unchanged and never contains errore', function (): void {
    $constants = lifecycleReadGateConstants();

    expect($constants['ORDERED_STATUSES'])->toBe(['in_attesa', 'in_corso', 'in_valutazione', 'completato']);
    expect($constants['ORDERED_STATUSES'])->not->toContain('errore');
});

test('D1 disjointness invariant: no off_progression member of any scope appears in ORDERED_STATUSES', function (): void {
    $constants = lifecycleReadGateConstants();

    foreach ($constants['SCOPE_RULES'] as $scope => $rule) {
        foreach ($rule['off_progression'] as $offProgressionStatus) {
            expect(in_array($offProgressionStatus, $constants['ORDERED_STATUSES'], true))
                ->toBeFalse("Scope '{$scope}': off_progression member '{$offProgressionStatus}' must never rank on ORDERED_STATUSES.");
        }
    }
});

test('D1 closure invariant: every off_progression member is a KNOWN_STATUSES value', function (): void {
    $constants = lifecycleReadGateConstants();

    foreach ($constants['SCOPE_RULES'] as $scope => $rule) {
        foreach ($rule['off_progression'] as $offProgressionStatus) {
            expect(in_array($offProgressionStatus, $constants['KNOWN_STATUSES'], true))
                ->toBeTrue("Scope '{$scope}': off_progression member '{$offProgressionStatus}' must be a known lifecycle status.");
        }
    }
});

test('Evaluation off_progression allow-list is empty — the errore allowance is Transcript-only', function (): void {
    $constants = lifecycleReadGateConstants();

    expect($constants['SCOPE_RULES']['evaluation']['off_progression'])->toBe([]);
});

// ── D2 — partial marker reuses the OLD (in_valutazione) threshold ──

test('isTranscriptPartial reuses the old in_valutazione threshold, not a new rule', function (string $status, bool $expectedPartial): void {
    $gate = new LifecycleReadGate;

    expect($gate->isTranscriptPartial($status))->toBe($expectedPartial);
})->with([
    'in_attesa is partial' => ['in_attesa', true],
    'in_corso is partial' => ['in_corso', true],
    'errore is partial' => ['errore', true],
    'in_valutazione is complete' => ['in_valutazione', false],
    'completato is complete' => ['completato', false],
    'an unrecognized status is partial (fail-closed in the disclosure direction too)' => ['quantum_superposition', true],
]);
