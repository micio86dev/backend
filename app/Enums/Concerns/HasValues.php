<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/**
 * `values(): list<string>` — every case's backing value, in declaration
 * order — for a `string`-backed enum, most often consumed by
 * `Illuminate\Validation\Rule::in()` on a `?filter=` query parameter (public
 * API and admin alike). Shared by every enum this codebase already gave the
 * IDENTICAL method to before this trait existed (step 5 review follow-up,
 * item 11): `App\Support\PublicApi\InterviewStatus`, `App\Enums\ProjectStatus`,
 * `App\Enums\OrgRole`, `App\Enums\AssessmentType` and `App\Enums\RoleCode`
 * each carried a byte-for-byte copy.
 */
trait HasValues
{
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
