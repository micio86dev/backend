<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `Export.status` lifecycle (public-api step 8, SPEC.md §3.3 "Exports",
 * `openapi.yaml`'s `Export.status` enum).
 *
 * Transitions:
 *   (insert) -> queued
 *   queued -> processing        (GenerateExportJob::handle() starts)
 *   processing -> ready         (streamed, checksummed, uploaded)
 *   processing -> failed        (any exception during generation)
 *
 * `expired` is reachable only by an OUT-OF-BAND process aging a `ready`
 * export's DOWNLOAD ARCHIVE past its window (`openapi.yaml`: "`expired`
 * refers to the export archive's download window, not to an interview") —
 * no code in this slice writes it; it exists in the enum because the public
 * contract names it as a legal value a caller must be able to parse.
 *
 * Only `queued`/`processing` participate in the "one active export per
 * organization" partial unique index (the owning migration's own docblock)
 * — `ready`/`failed`/`expired` are all terminal-for-concurrency, even though
 * `ready` is not terminal for the row's own lifecycle (it may later expire).
 */
enum ExportStatus: string
{
    use HasValues;

    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';
}
