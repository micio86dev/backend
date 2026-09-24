<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * BEAI organizational role code (CLAUDE.md binding domain constraint — the
 * fixed set of 5: ICO, FLL, MLL, BUL, SRX; public-api step 5, Part A item 5).
 *
 * NOT a Spatie authorization role — see `App\Enums\OrgRole`'s own docblock
 * for that distinction. This is the domain concept carried as `role_code` on
 * `Project`/`Participant`, describing which role an interview assesses.
 *
 * Backed as a code-level enum, like `OrgRole`, rather than a query against
 * the framework catalogue tables: this 5-role set is the binding constraint
 * itself (CLAUDE.md "Roles (5): ..."), not tenant-configurable data — unlike
 * the 18 standard COMPETENCIES, which genuinely are catalogue-driven and
 * intentionally not hardcoded anywhere in this codebase.
 *
 * Single source of truth for the `ICO|FLL|MLL|BUL|SRX` value set —
 * previously a hand-written `in:ICO,FLL,MLL,BUL,SRX` literal that existed
 * ONLY in `App\Http\Controllers\PublicApi\ProjectController::validateFilters()`;
 * the admin `UpdateProjectRequest`/`StoreProjectRequest` validated `role_code`
 * with a bare `'string'` rule and left the real check to the cross-field
 * `withValidator` composition-gate logic elsewhere in those classes (that
 * logic is untouched here — this enum only replaces the FORMAT check, not
 * the composition/immutability rules). Matches `openapi.yaml`'s `RoleCode`
 * schema exactly.
 */
enum RoleCode: string
{
    case Ico = 'ICO';
    case Fll = 'FLL';
    case Mll = 'MLL';
    case Bul = 'BUL';
    case Srx = 'SRX';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
