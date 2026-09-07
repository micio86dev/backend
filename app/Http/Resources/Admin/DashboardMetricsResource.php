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
 * `ai_requests`, PLUS the estimated spend derived from them.
 *
 * This said "NEVER carries a cost/currency field", seven lines above a
 * constructor `@param` declaring `costs`. True when written and false since a
 * later change added the estimate — the same two-documents-one-truth drift that
 * made `AGENTS.md` a symlink rather than a copy. What remains true, and is a
 * different statement, is that there is no BILLING data: no price column, no
 * subscription, no MRR (observability delta, ruling 3). The costs here are
 * derived estimates, not invoices.
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
     * The shape is declared for the exporter, not left as `array<string,
     * mixed>`.
     *
     * Scramble reads `@scramble-return`; without it this resource published a
     * bare object, so the backoffice hand-wrote the interface and defended it
     * with "Scramble cannot trace a shape through a passthrough `toArray()`".
     * That was never the problem — the tag was simply missing, while the
     * constructor `@param` above had spelled the shape out all along. The
     * consequence was real: rename `costs.total_usd` here and nothing failed,
     * not the client-drift check and not a typecheck, and the figure an
     * operator reconciles against an invoice rendered from `undefined`.
     *
     * Mirrors the `@param` deliberately. Two declarations of one shape is the
     * defect this file already carries elsewhere; they are adjacent so a change
     * to either is visibly a change to both.
     *
     * @return array<string, mixed>
     *
     * @scramble-return array{participants_by_status: array<string, int>, evaluations_by_status: array<string, int>, completion_rate: float, ai_usage: array{input_tokens: int, output_tokens: int, latency_ms_p50: int|null, latency_ms_p95: int|null}, costs: array{scoring_usd: float, conversation_usd: float, total_usd: float, currency: string}}
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
