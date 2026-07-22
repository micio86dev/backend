<?php

declare(strict_types=1);

/**
 * RED — Task 22.4: CompletionGate unit tests (C9 D5 CC1 gate).
 *
 * Verifies:
 * (a) 10/10 valid → completed.
 * (b) 9/10 valid (90%) → completed (uses >=, boundary inclusive).
 * (c) 8/10 valid (80%) → pending.
 * (d) 7/10 valid, 2 unscorable (role_no_bars), default policy → pending (7/10=70%).
 * (e) totalCount == 0 → ZeroCompetenciesInvariantException.
 *
 * Refs spec: D5 CC1 "Gate formula", "Invariant guard: total_competencies == 0 → errore".
 */

use App\Enums\EvaluationStatus;
use App\Exceptions\Scoring\ZeroCompetenciesInvariantException;
use App\Services\Scoring\CompletionGate;

test('(a) 10/10 valid → completed', function (): void {
    $gate = new CompletionGate;
    expect($gate->evaluate(10, 10))->toBe(EvaluationStatus::Completed);
});

test('(b) 9/10 valid = 90% → completed (>= boundary inclusive)', function (): void {
    $gate = new CompletionGate;
    expect($gate->evaluate(9, 10))->toBe(EvaluationStatus::Completed);
});

test('(c) 8/10 valid = 80% → pending', function (): void {
    $gate = new CompletionGate;
    expect($gate->evaluate(8, 10))->toBe(EvaluationStatus::Pending);
});

test('(d) 7/10 valid (2 unscorable in denominator) = 70% → pending', function (): void {
    // totalCount = 10 (includes 2 unscorables), validCount = 7 (only scored valid ones)
    $gate = new CompletionGate;
    expect($gate->evaluate(7, 10))->toBe(EvaluationStatus::Pending);
});

test('(e) totalCount == 0 → ZeroCompetenciesInvariantException', function (): void {
    $gate = new CompletionGate;
    expect(fn () => $gate->evaluate(0, 0))->toThrow(ZeroCompetenciesInvariantException::class);
});

test('0/10 valid → pending', function (): void {
    $gate = new CompletionGate;
    expect($gate->evaluate(0, 10))->toBe(EvaluationStatus::Pending);
});
