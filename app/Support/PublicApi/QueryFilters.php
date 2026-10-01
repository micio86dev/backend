<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

use Illuminate\Http\Request;

/**
 * The query parameters of a `/v1` request that were actually provided.
 *
 * An empty filter means "not provided". A browser form submits every field it
 * has, so `?status=` is how an unfiltered form arrives, and a caller that
 * builds a query from optional values does the same. Laravel's `TrimStrings`
 * and `ConvertEmptyStringsToNull` middleware turn such a value into `null`
 * before a controller runs, and a bare `string`, `in:` or `array` rule refuses
 * `null`: every list filter answered `400 validation_failed` for an empty
 * value, and the `!== ''` guards that read the filters were unreachable.
 *
 * Validating this array instead of `$request->query()` makes the rule one
 * decision in one place, not a `nullable` to remember on every new filter:
 * a key whose value is `null`, empty or only whitespace is removed, at any
 * depth, and an array left with nothing in it is removed too (`?metadata[a]=`
 * names no metadata filter, and four of them do not trip a "three at most"
 * cap). A value that IS provided is passed through untouched, so a non-empty
 * invalid one still fails its rule.
 *
 * It only shapes what is validated. Tenant and mode scoping live in the
 * controllers' base query and are not read from here.
 */
final class QueryFilters
{
    /**
     * @return array<array-key, mixed>
     */
    public static function provided(Request $request): array
    {
        return self::withoutEmpty($request->query());
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function withoutEmpty(array $values): array
    {
        $kept = [];

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $value = self::withoutEmpty($value);

                if ($value === []) {
                    continue;
                }
            }

            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }

            $kept[$key] = $value;
        }

        return $kept;
    }
}
