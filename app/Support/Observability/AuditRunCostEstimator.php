<?php

declare(strict_types=1);

namespace App\Support\Observability;

/**
 * Turns audit token counts into an estimated USD cost — the audit's OWN
 * meter (scoring-audit-jev, design D8), never summed into
 * `AiRequestCostEstimator`'s scoring meter (proposal AD-2: two different
 * vendors, two different meters — one total would be a number with no
 * owner).
 *
 * Deliberately diverges from `AiRequestCostEstimator` on the unknown-model
 * case: returns NULL, not 0.0. `AiRequestCostEstimator` returns 0.0 for an
 * unknown model ON PURPOSE so a missing rate shows up as a zero-cost
 * anomaly in the scoring dashboard — reusing that signal here would make an
 * unpriced audit run read as a FREE one, in a table whose entire purpose is
 * answering "what does this cost". NULL says *unpriced*, a different fact
 * from *free* — the doctrine already ratified one change ago
 * (pluggable-conversation-llm D1: "NULL means the vendor does not publish
 * this, and that is a different fact from zero… the cost is refused, not
 * coerced").
 *
 * Rates live in `config('scoring.audit.cost_rates_usd_per_million')`, empty
 * until design.md C-C's rate-card verification lands. Token counts are
 * recorded on the run row regardless, so an unpriced run still carries the
 * measurement AD-1 needs — only the dollar figure is withheld.
 */
final class AuditRunCostEstimator
{
    /**
     * @param  int  $inputTokens  prompt tokens billed
     * @param  int  $outputTokens  completion tokens billed
     */
    public function estimate(string $model, int $inputTokens, int $outputTokens): ?float
    {
        /** @var array<string, array{input: float, output: float}> $rates */
        $rates = config('scoring.audit.cost_rates_usd_per_million', []);

        $rate = $rates[$model] ?? null;

        if ($rate === null) {
            // Unknown/unpriced model: refuse the figure rather than coerce
            // it to a value that reads as "free" (D8).
            return null;
        }

        $cost = ($inputTokens / 1_000_000) * (float) $rate['input']
              + ($outputTokens / 1_000_000) * (float) $rate['output'];

        // 6dp matches the column (indicator_score_audit_runs.estimated_cost_usd,
        // decimal(12,6)) — the same reasoning AiRequestCostEstimator states for
        // itself: rounding to 2dp/4dp would floor a cheap call to zero.
        return round($cost, 6);
    }
}
