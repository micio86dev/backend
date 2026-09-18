<?php

declare(strict_types=1);

namespace App\Enums\Audit;

/**
 * The terminal outcome of one `indicator_score_audit_runs` row (proposal
 * AD-3, design D4). A TypeSafe outage is a RECORDED outcome, never an
 * absent one — see `AuditOutcomeReason` for the per-indicator vocabulary
 * this is distinct from.
 */
enum AuditRunStatus: string
{
    /** No competency's judge call threw, and no verdict was malformed. */
    case Completed = 'completed';

    /** At least one competency was unavailable or malformed, but not all. */
    case Partial = 'partial';

    /** Every judgeable indicator in the run ended unavailable or malformed. */
    case Failed = 'failed';
}
