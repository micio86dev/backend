<?php

declare(strict_types=1);

namespace App\Support\Observability;

/**
 * Turns token counts into an estimated USD cost (C13, design D2).
 *
 * "Estimated" is in the name and stays there. This is not an invoice, it is not
 * authoritative for billing, and any UI reading it must present it as an
 * estimate.
 *
 * The value is computed at WRITE time and stored, never computed on read.
 * Computing on read would let a later price change silently rewrite history —
 * last quarter's reported spend would move because this quarter's rates did. A
 * stored estimate is wrong only in the way an estimate is always wrong, and it
 * stays wrong consistently, which is what makes a trend line meaningful.
 *
 * Rates live in `config/scoring.php`. An unknown model yields 0.0 rather than
 * throwing: a missing rate must never be the thing that stops a scoring job or
 * loses the record of a call that was made. It shows up as a zero-cost row,
 * which is visible in the dashboard as an anomaly — the right place to notice
 * it.
 */
final class AiRequestCostEstimator
{
    /**
     * @param  int  $inputTokens  prompt tokens billed
     * @param  int  $outputTokens  completion tokens billed
     */
    public function estimate(string $model, int $inputTokens, int $outputTokens): float
    {
        /** @var array<string, array{input: float, output: float}> $rates */
        $rates = config('scoring.cost_rates_usd_per_million', []);

        $rate = $rates[$model] ?? null;

        if ($rate === null) {
            // Unknown model: record the call at zero rather than lose it.
            return 0.0;
        }

        $cost = ($inputTokens / 1_000_000) * (float) $rate['input']
              + ($outputTokens / 1_000_000) * (float) $rate['output'];

        // 6dp matches the column. Per-call costs on cheap models run to
        // fractions of a cent; rounding to 2dp would floor a whole campaign to
        // zero and make the dashboard confidently report nothing was spent.
        return round($cost, 6);
    }
}
