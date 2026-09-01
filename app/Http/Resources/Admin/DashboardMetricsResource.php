<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin DashboardMetricsResource (C11 D5/D7).
 *
 * Wraps the array produced by DashboardController::metrics(). Org-scoped
 * usage metrics only — DB-backed token counts and latency percentiles from
 * `ai_requests`. NEVER carries a cost/currency field: no price column exists
 * anywhere in the schema (2026_07_22_000004_create_ai_requests_table.php:54-61)
 * and no billing/subscription/MRR data exists either (observability delta,
 * ruling 3).
 */
class DashboardMetricsResource extends JsonResource
{
    /**
     * @param  array{
     *     participants_by_status: array<string, int>,
     *     evaluations_by_status: array<string, int>,
     *     completion_rate: float,
     *     ai_usage: array{input_tokens: int, output_tokens: int, latency_ms_p50: int|null, latency_ms_p95: int|null},
     *     costs: array{scoring_usd: float, conversation_usd: float, total_usd: float, currency: string}
     * }  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }

    /**
     * Force JSON_PRESERVE_ZERO_FRACTION on the FIRST encode — see
     * EvaluationResource::jsonOptions() for why this must be set here and
     * not in withResponse() (too late — the value already round-tripped
     * through a lossy encode/decode by then). `completion_rate` is a float
     * per D7's contract (a ratio in [0,1], e.g. round(2/3, 4)); losing the
     * decimal on the whole-number edge cases (0.0, 1.0) would make it
     * indistinguishable from an integer for API consumers with strict schemas.
     */
    public function jsonOptions(): int
    {
        return JSON_PRESERVE_ZERO_FRACTION;
    }
}
