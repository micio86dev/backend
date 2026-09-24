<?php

declare(strict_types=1);

namespace App\Rules\PublicApi;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a BEAI Public API (`/v1`) `?created_after=`/`?created_before=`
 * query value as STRICT ISO 8601 (step 5 review follow-up, item 6) —
 * `App\Http\Controllers\PublicApi\InterviewController::validateFilterFormats()`
 * previously used the plain `'date'` rule, which accepts anything PHP's
 * `strtotime()` can parse — including relative phrases (`next monday`,
 * `tomorrow`, `+1 day`) that read as a genuine date to a human filing a
 * calling-system report but resolve to a DIFFERENT instant every time the
 * request runs, silently drifting the filter window rather than failing
 * loud.
 *
 * Four accepted shapes, matching SPEC.md §3.2 "Timestamps: ISO 8601 UTC
 * with `Z`" plus the numeric-offset form every other ISO 8601 producer
 * (including this API's own JSON responses' `toISOString()`) may emit:
 * with or without fractional seconds, `Z` or a numeric `±HH:MM` offset.
 * `DateTimeImmutable::createFromFormat()` — not `strtotime()` — is what
 * makes this strict: it returns `false` (not a best-effort guess) for
 * ANY input that does not match one of these four shapes exactly,
 * relative phrases included.
 */
final class Iso8601DateTime implements ValidationRule
{
    /**
     * @var list<string>
     */
    private const FORMATS = [
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:s.uP',
        'Y-m-d\TH:i:s\Z',
        'Y-m-d\TH:i:s.u\Z',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || self::parse($value) === null) {
            $fail('The :attribute must be a strict ISO 8601 date-time, e.g. 2026-01-01T00:00:00Z.');
        }
    }

    /**
     * The SAME parse this rule validates with, reused by the caller
     * (`InterviewController::index()`) to normalise an already-validated
     * `?created_after=`/`?created_before=` value into the `CarbonImmutable`
     * the comparison actually runs against — never the raw string, and
     * never re-parsed with a looser method than the one that validated it.
     */
    public static function parse(string $value): ?CarbonImmutable
    {
        foreach (self::FORMATS as $format) {
            // Unlike native `DateTimeImmutable::createFromFormat()` (which
            // returns `false` on a mismatch), Carbon's own override THROWS
            // `InvalidFormatException` for a value that does not fully
            // satisfy the format — e.g. `2026-01-01` against
            // `Y-m-d\TH:i:sP` ("Not enough data available to satisfy
            // format"). Each candidate format is tried independently, so a
            // mismatch here means "try the next shape", never a caller-
            // visible failure — mirrors the `try`/`catch (InvalidArgumentException)`
            // discipline `App\Support\PublicApi\CursorPage::decodeCursor()`
            // already applies to the identical Carbon behaviour.
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $value);
            } catch (InvalidFormatException) {
                continue;
            }

            if ($parsed instanceof CarbonImmutable) {
                return $parsed;
            }
        }

        return null;
    }
}
