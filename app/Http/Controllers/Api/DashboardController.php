<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\DashboardActivityResource;
use App\Http\Resources\Admin\DashboardMetricsResource;
use App\Models\AiRequest;
use App\Models\Evaluation;
use App\Support\Admin\AdminParticipantReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Admin DashboardController (C11 — Admin Dashboards, PR A3, D7).
 *
 * GET /api/dashboard/activity — the most recently updated candidates.
 * GET /api/dashboard/metrics — org-scoped participants-by-status,
 * evaluations-by-status, completion rate, and (from `ai_requests`) summed
 * token usage + p50/p95 latency.
 *
 * Correction to the proposal, preserved from design D7: there is NO cost
 * column anywhere in the schema
 * (2026_07_22_000004_create_ai_requests_table.php:54-61 stores tokens and
 * latency only) — this endpoint ships token usage, not monetary cost.
 * Subscription/MRR/trial metrics stay out of scope (observability delta,
 * ruling 3) — no billing schema exists to source them from.
 *
 * Participant counts go through AdminParticipantReader::listQuery() — the
 * same tenant-safe + RBAC-safe path the list endpoint uses — never a bare
 * static call on the Participant model directly (arch-tested,
 * AdminTenancySafetyArchTest task 2.3b).
 * Evaluation/AiRequest both extend TenantModel, so the ambient
 * TenantContext global scope already restricts them to the caller's org —
 * no reader indirection needed for those two.
 */
final class DashboardController extends Controller
{
    /**
     * Rows returned by the activity feed. Ten is what fits above the fold at
     * the 1280x800 minimum viewport (DESIGN.md §2) without the dashboard
     * turning into a scrolling list.
     */
    private const ACTIVITY_LIMIT = 10;

    public function __construct(
        private readonly AdminParticipantReader $reader,
    ) {}

    /**
     * The most recently updated candidates, newest first.
     *
     * GET /api/dashboard/activity
     * Auth: auth:api (same `viewAny` gate as the candidate list, via
     * AdminParticipantReader — this is that list's data, not a wider view)
     *
     * Hard-capped: the dashboard answers "what just happened", and a feed that
     * can grow without bound is a second candidate list wearing a summary's
     * clothes. Anyone who needs more has /participants, which paginates.
     *
     * `with('project:id,name')` is not an optimisation detail — without it
     * every row would fire its own query for a single string.
     */
    public function activity(): JsonResponse
    {
        $recent = $this->reader->listQuery()
            ->with('project:id,name')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::ACTIVITY_LIMIT)
            ->get();

        return DashboardActivityResource::collection($recent)->response();
    }

    public function metrics(): JsonResponse
    {
        $participantsByStatus = $this->reader->listQuery()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count);

        $totalParticipants = $participantsByStatus->sum();
        $completedParticipants = (int) ($participantsByStatus->get('completato') ?? 0);
        $completionRate = $totalParticipants > 0
            ? round($completedParticipants / $totalParticipants, 4)
            : 0.0;

        $evaluationsByStatus = Evaluation::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count);

        $sortedLatencies = AiRequest::query()
            ->pluck('latency_ms')
            ->map(fn ($value) => (int) $value)
            ->sort()
            ->values();

        return (new DashboardMetricsResource([
            'participants_by_status' => $participantsByStatus->all(),
            'evaluations_by_status' => $evaluationsByStatus->all(),
            'completion_rate' => $completionRate,
            'ai_usage' => [
                'input_tokens' => (int) AiRequest::query()->sum('input_tokens'),
                'output_tokens' => (int) AiRequest::query()->sum('output_tokens'),
                'latency_ms_p50' => $this->percentile($sortedLatencies, 0.5),
                'latency_ms_p95' => $this->percentile($sortedLatencies, 0.95),
            ],
        ]))->response();
    }

    /**
     * Nearest-rank percentile over a pre-sorted, zero-indexed collection.
     *
     * Bounded, org-scoped ai_requests dataset — the same "compute in PHP,
     * revisit at scale" posture as D9's buffered-download decision; no
     * DB-specific percentile function used, so this stays portable.
     *
     * @param  Collection<int, int>  $sorted
     */
    private function percentile(Collection $sorted, float $p): ?int
    {
        if ($sorted->isEmpty()) {
            return null;
        }

        $index = (int) ceil($p * $sorted->count()) - 1;
        $index = max(0, min($sorted->count() - 1, $index));

        return $sorted->get($index);
    }
}
