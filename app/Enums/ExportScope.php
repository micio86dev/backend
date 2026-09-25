<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `Export.scope` / `CreateExportRequest.scope` (public-api step 8,
 * `openapi.yaml`'s `CreateExportRequest` schema). `All` and `Interviews`
 * export identical PER-INTERVIEW content today (`App\Jobs\PublicApi\
 * GenerateExportJob` — there is no separate account-level resource this
 * slice exports beyond interviews); the distinction is kept because the
 * contract already defines two values and a future slice (organization
 * settings, project catalogue) may widen what `all` includes without a
 * breaking contract change.
 */
enum ExportScope: string
{
    use HasValues;

    case All = 'all';
    case Interviews = 'interviews';
}
