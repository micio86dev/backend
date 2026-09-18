<?php

declare(strict_types=1);

/**
 * RED — P3a.12: AuditRunCostEstimator (scoring-audit-jev, design D8).
 *
 * Mirrors AiRequestCostEstimator's shape but asserts the DELIBERATE
 * divergence D8 calls out: an unknown judge model id yields NULL, never
 * 0.0. AiRequestCostEstimator returns 0.0 on purpose so a missing rate
 * shows up as a zero-cost anomaly in the scoring dashboard — reusing that
 * here would make an unpriced audit run read as a FREE one, in a table
 * whose entire purpose is answering "what does this cost". NULL says
 * "unpriced", a different fact from "free" (pluggable-conversation-llm D1's
 * ratified doctrine, applied here).
 */

use App\Support\Observability\AuditRunCostEstimator;

beforeEach(function (): void {
    config()->set('scoring.audit.cost_rates_usd_per_million', [
        'jev-1' => ['input' => 3.0, 'output' => 15.0],
    ]);
});

test('returns null, never 0.0, for an unknown judge model id', function (): void {
    $estimator = new AuditRunCostEstimator;

    expect($estimator->estimate('unknown-model', 1000, 500))->toBeNull();
});

test('returns a correctly 6dp-rounded figure for a known model', function (): void {
    $estimator = new AuditRunCostEstimator;

    // (1000/1e6)*3.0 + (500/1e6)*15.0 = 0.003 + 0.0075 = 0.0105
    expect($estimator->estimate('jev-1', 1000, 500))->toBe(0.0105);
});

test('rounds to 6dp rather than flooring a cheap call to zero', function (): void {
    $estimator = new AuditRunCostEstimator;

    // (1/1e6)*3.0 = 0.000003 — would floor to 0.0 at 2dp/4dp, must survive at 6dp.
    expect($estimator->estimate('jev-1', 1, 0))->toBe(0.000003);
});

test('an empty rate table yields null for every model, never a crash', function (): void {
    config()->set('scoring.audit.cost_rates_usd_per_million', []);

    $estimator = new AuditRunCostEstimator;

    expect($estimator->estimate('jev-1', 1000, 500))->toBeNull();
});
