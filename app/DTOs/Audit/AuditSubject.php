<?php

declare(strict_types=1);

namespace App\DTOs\Audit;

/**
 * The ENTIRE state that may leave BEAI for one indicator (proposal AD-6,
 * design D2). Built from exactly four persisted `IndicatorScore` columns —
 * no transcript, no participant identity, no email, no database identifier.
 */
final readonly class AuditSubject
{
    /**
     * @param  int  $indicatorScoreId  LOCAL correlation key — NEVER serialized
     *                                 onto the wire. `JevRequestBuilder`
     *                                 assigns each subject an ordinal question
     *                                 key (`i1`, `i2`, …) instead; sending a
     *                                 database id to a third party would leak
     *                                 an internal identifier into a vendor's
     *                                 logs (design D2).
     * @param  list<string>  $excerpts  Verbatim excerpts already persisted on
     *                                  this row.
     */
    public function __construct(
        public int $indicatorScoreId,
        public int $position,
        public string $indicatorText,
        public int $score,
        public string $explanation,
        public array $excerpts,
    ) {}
}
