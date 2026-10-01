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
 * decision in one place, not a `nullable` to remember on every new filter: a
 * key whose value is `null`, empty or only whitespace is removed. A value that
 * IS provided is passed through untouched, so a non-empty invalid one still
 * fails its rule.
 *
 * Only an empty SCALAR is "not provided". `?status[]=` is an array, and a
 * scalar-only filter refuses an array whatever it holds, exactly as it refuses
 * `?status[]=1`: dropping the first would make the same wrong shape succeed or
 * fail on the value inside it. A parameter that legitimately takes a map
 * (`metadata`) is named by the caller in `$mapFields`; there, empty scalar
 * entries are dropped (`?metadata[a]=`), the map is dropped when nothing is
 * left, and an entry that is itself an array (`?metadata[a][]=`) is kept so its
 * rule can refuse it.
 *
 * It only shapes what is validated. Tenant and mode scoping live in the
 * controllers' base query and are not read from here.
 */
final class QueryFilters
{
    /**
     * @param  list<string>  $mapFields  parameters that legitimately take a map of scalars
     * @return array<array-key, mixed>
     */
    public static function provided(Request $request, array $mapFields = []): array
    {
        $kept = [];

        foreach ($request->query() as $key => $value) {
            if (is_array($value) && in_array($key, $mapFields, true)) {
                $value = array_filter($value, static fn (mixed $entry): bool => is_array($entry) || ! self::isEmpty($entry));

                if ($value === []) {
                    continue;
                }
            } elseif (! is_array($value) && self::isEmpty($value)) {
                continue;
            }

            $kept[$key] = $value;
        }

        return $kept;
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
