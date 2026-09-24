<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Project lifecycle status (project-config C4; public-api step 5, Part A
 * item 5).
 *
 * Single source of truth for the `draft|active|archived` value set —
 * previously duplicated as a hand-written `Rule::in([...])` literal in both
 * `App\Http\Requests\StoreProjectRequest`/`UpdateProjectRequest` (admin) and
 * `App\Http\Controllers\PublicApi\ProjectController::validateFilters()`
 * (public API `?status=` filter), with nothing keeping the three lists in
 * sync if a status were ever added or renamed. Matches
 * `openapi.yaml`'s `ProjectStatus` schema and `public_api/openapi.yaml`'s
 * copy of it exactly.
 */
enum ProjectStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
