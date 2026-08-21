<?php

declare(strict_types=1);

/**
 * SessionCostEstimator (C11, design D5).
 *
 * The estimate is DERIVED at read time and never stored: rates change, and a
 * figure computed under an old rate becomes a number nobody can reproduce.
 * These tests pin that the arithmetic follows config, and that the estimator
 * declines rather than guesses when it lacks the inputs.
 *
 * REQ: Session cost is derived from stored timings, not recorded
 */

use App\Models\InterviewSession;
use App\Models\InterviewSessionLivePeriod;
use App\Services\Proctoring\SessionCostEstimator;
use Illuminate\Support\Collection;

/**
 * A session shaped by NAMED live periods (interview-session-started-at, D8)
 * — never a bare `started_at`/`ended_at` pair. `liveSeconds()` reads the
 * `livePeriods` relation, so it is set directly here (in-memory, never
 * persisted — this suite is a pure Unit-tier estimator test).
 */
function sessionWith(string $provider, array $periods): InterviewSession
{
    $session = new InterviewSession;
    $session->forceFill(['provider' => $provider]);
    $session->setRelation('livePeriods', Collection::make(array_map(
        fn (array $p): InterviewSessionLivePeriod => (new InterviewSessionLivePeriod)->forceFill($p),
        $periods,
    )));

    return $session;
}

function closedPeriod(?string $started, ?string $ended): array
{
    return ['started_at' => $started, 'ended_at' => $ended];
}

test('heygen cost is minutes x credits/min x $/credit', function (): void {
    config()->set('interview.rates.heygen.credits_per_minute', 2);
    config()->set('interview.rates.heygen.usd_per_credit', 0.10);

    $estimate = (new SessionCostEstimator)->estimate(
        sessionWith('heygen', [closedPeriod('2026-03-01 10:00:00', '2026-03-01 10:10:00')])
    );

    // 10 min * 2 credits * $0.10 = $2.00
    expect($estimate['minutes'])->toBe(10.0)
        ->and($estimate['usd'])->toBe(2.0)
        ->and($estimate['provider'])->toBe('heygen');
});

test('tavus cost is minutes x $/min', function (): void {
    config()->set('interview.rates.tavus.usd_per_minute', 0.37);

    $estimate = (new SessionCostEstimator)->estimate(
        sessionWith('tavus', [closedPeriod('2026-03-01 10:00:00', '2026-03-01 10:30:00')])
    );

    expect($estimate['usd'])->toBe(11.1);
});

test('a resumed session sums two closed periods and prices live minutes, not the span across the gap', function (): void {
    config()->set('interview.rates.tavus.usd_per_minute', 1.00);

    // 5 min + 5 min = 10 live minutes, despite a 3-hour gap between them.
    $estimate = (new SessionCostEstimator)->estimate(
        sessionWith('tavus', [
            closedPeriod('2026-03-01 10:00:00', '2026-03-01 10:05:00'),
            closedPeriod('2026-03-01 13:05:00', '2026-03-01 13:10:00'),
        ])
    );

    expect($estimate['minutes'])->toBe(10.0)
        ->and($estimate['usd'])->toBe(10.0);
});

test('a rate override changes the result, which is why rates are config', function (): void {
    config()->set('interview.rates.tavus.usd_per_minute', 1.00);

    $estimate = (new SessionCostEstimator)->estimate(
        sessionWith('tavus', [closedPeriod('2026-03-01 10:00:00', '2026-03-01 10:10:00')])
    );

    expect($estimate['usd'])->toBe(10.0);
});

test('a session with no closed period yields no estimate rather than a partial one', function (): void {
    // Still open (mid-flight) — never clamped with now().
    expect((new SessionCostEstimator)->estimate(
        sessionWith('heygen', [['started_at' => '2026-03-01 10:00:00', 'ended_at' => null]])
    ))->toBeNull();
});

test('a session that never went live yields no estimate', function (): void {
    expect((new SessionCostEstimator)->estimate(
        sessionWith('heygen', [])
    ))->toBeNull();
});

test('a non-positive duration yields no estimate', function (): void {
    expect((new SessionCostEstimator)->estimate(
        sessionWith('heygen', [closedPeriod('2026-03-01 10:00:00', '2026-03-01 10:00:00')])
    ))->toBeNull();
});

test('an unrecognised provider yields no estimate, not zero', function (): void {
    // Zero reads as "this was free", which is a different claim from
    // "this cannot be priced".
    expect((new SessionCostEstimator)->estimate(
        sessionWith('some_future_provider', [closedPeriod('2026-03-01 10:00:00', '2026-03-01 10:10:00')])
    ))->toBeNull();
});
