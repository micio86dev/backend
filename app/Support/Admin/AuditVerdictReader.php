<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\IndicatorScoreAudit;
use App\Models\IndicatorScoreAuditRun;

/**
 * The ONLY class in `app/` permitted to query either audit table
 * (scoring-audit-jev design D10) — what lets `AuditAppendOnlyArchTest` ban
 * `::where(`/`::find(` everywhere else outright, exactly as
 * `AiRequestAppendOnlyArchTest` already does for `AiRequest`.
 *
 * Both queries run under the ambient tenant scope (`IndicatorScoreAuditRun`
 * and `IndicatorScoreAudit` both extend `TenantModel`, C2) — NEVER
 * `withoutGlobalScopes()`, the same rule `AdminEvaluationSerializer`'s own
 * docblock states for itself.
 */
final class AuditVerdictReader
{
    /**
     * The most recently WRITTEN run for an evaluation, or null when the
     * evaluation has never been audited.
     *
     * "Latest run" — deliberately NOT "latest verdict per indicator" (design
     * D9): a later run may legitimately skip an indicator an earlier run
     * judged (its excerpts were purged, say), and showing the earlier
     * judgment beside the later run's counters would produce a report whose
     * coverage numbers contradict its own rows.
     */
    public function latestRunFor(int $evaluationId): ?IndicatorScoreAuditRun
    {
        return IndicatorScoreAuditRun::where('evaluation_id', $evaluationId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Every verdict one run wrote, keyed by `indicator_score_id` —
     * `UNIQUE(audit_run_id, indicator_score_id)` at the database guarantees
     * this key can never collide.
     *
     * @return array<int, IndicatorScoreAudit>
     */
    public function verdictsForRun(int $runId): array
    {
        return IndicatorScoreAudit::where('audit_run_id', $runId)
            ->get()
            ->keyBy('indicator_score_id')
            ->all();
    }

    /**
     * Declared by design D10's own contract and intentionally NEVER the
     * mechanism behind the operator route's `409 audit_already_running`
     * refusal — that refusal is a Redis lock (design D7), taken by the
     * controller and released by the job, precisely because a `running`
     * status on either audit row would force an UPDATE on a table this
     * change arch-tests as append-only (`AuditAppendOnlyArchTest`), and
     * "append-only except for the status column" is not append-only.
     *
     * Neither audit table EVER persists an in-progress state — a run row is
     * written exactly once, at the end (design D6) — so there is no row this
     * method could read that would answer "is one running right now" without
     * reintroducing the exact TOCTOU window D7 explicitly rejects for its
     * alternative (b) ("a `SELECT … WHERE status = 'running'` existence
     * check … is a TOCTOU window exactly the width of a double-click — the
     * failure it exists to prevent"). This method therefore always returns
     * `false`: it exists only to complete the reader's declared public
     * surface (D10), and MUST NEVER be consulted for a refusal decision —
     * `tests/Unit/Support/Admin/AuditVerdictReaderTest.php` asserts both
     * halves of this contract directly.
     */
    public function hasRunInFlight(int $evaluationId): bool
    {
        return false;
    }
}
