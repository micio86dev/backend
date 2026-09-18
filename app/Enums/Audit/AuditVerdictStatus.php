<?php

declare(strict_types=1);

namespace App\Enums\Audit;

/**
 * The status of one `indicator_score_audits` row (proposal AD-3, design
 * D4/C-D). A closed enum under a database CHECK: `Judged` MUST carry a
 * non-null `support_probability` and a null `outcome_reason`; every other
 * status MUST carry the reverse — the identical equivalence shape as
 * `indicator_scores_unassessable_reason_check`.
 */
enum AuditVerdictStatus: string
{
    /** The judge answered and the verdict was usable. */
    case Judged = 'judged';

    /** The competency-level judge call itself threw — the vendor could not be asked. */
    case Unavailable = 'unavailable';

    /** The vendor answered, but this subject's verdict could not be used. */
    case Malformed = 'malformed';

    /** Out of judgment scope by AD-4's evidence-presence rule — never sent to the judge. */
    case Skipped = 'skipped';
}
