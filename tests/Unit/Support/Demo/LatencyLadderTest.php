<?php

declare(strict_types=1);

/**
 * The 26 hand-authored `ai_requests.latency_ms` values (design D14), fed
 * through the SAME nearest-rank formula `DashboardController::percentile()`
 * uses, must land on p50=1284ms and p95=7436ms — both exact integers, both
 * non-round (never a multiple of 100), so a test can pin them precisely.
 *
 * Pure — no DB: the ladder is entirely inside `DemoDataset::aiRequestCalls()`
 * fixture data, and the percentile formula is reproduced here exactly rather
 * than invoked through the controller (which requires seeded rows) — the
 * formula ITSELF is exercised end-to-end by
 * `Feature\Demo\DashboardMetricsPopulatedTest`.
 */

use App\Support\Demo\DemoDataset;

function nearestRankPercentile(array $sorted, float $p): int
{
    $count = count($sorted);
    $index = (int) ceil($p * $count) - 1;
    $index = max(0, min($count - 1, $index));

    return $sorted[$index];
}

test('the 26 authored latencies produce p50=1284 and p95=7436 under nearest-rank percentile', function (): void {
    $latencies = collect(DemoDataset::aiRequestCalls())
        ->flatten(1)
        ->pluck('latency_ms')
        ->sort()
        ->values()
        ->all();

    expect($latencies)->toHaveCount(26);

    expect(nearestRankPercentile($latencies, 0.5))->toBe(1284);
    expect(nearestRankPercentile($latencies, 0.95))->toBe(7436);
});

test('no authored latency is a round multiple of 100', function (): void {
    $latencies = collect(DemoDataset::aiRequestCalls())->flatten(1)->pluck('latency_ms');

    foreach ($latencies as $latency) {
        expect($latency % 100)->not->toBe(0);
    }
});

test('every authored latency is unique', function (): void {
    $latencies = collect(DemoDataset::aiRequestCalls())->flatten(1)->pluck('latency_ms');

    expect($latencies->unique()->count())->toBe($latencies->count());
});
