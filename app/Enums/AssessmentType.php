<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * BEAI assessment type (CLAUDE.md binding domain constraint — mutually
 * exclusive `standard`/`potential`, immutable after go-live; public-api
 * step 5, Part A item 5).
 *
 * Single source of truth for the `standard|potential` value set —
 * previously duplicated as a hand-written `Rule::in([...])` literal in both
 * `App\Http\Requests\StoreProjectRequest`/`UpdateProjectRequest` (admin) and
 * `App\Http\Controllers\PublicApi\ProjectController::validateFilters()`
 * (public API `?assessment_type=` filter). Matches `openapi.yaml`'s
 * `AssessmentType` schema exactly.
 */
enum AssessmentType: string
{
    case Standard = 'standard';
    case Potential = 'potential';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
