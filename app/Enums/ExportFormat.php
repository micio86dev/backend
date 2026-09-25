<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * `Export.format` / `CreateExportRequest.format` (public-api step 8,
 * `openapi.yaml`'s `CreateExportRequest` schema).
 */
enum ExportFormat: string
{
    use HasValues;

    case Jsonl = 'jsonl';
    case Csv = 'csv';
}
