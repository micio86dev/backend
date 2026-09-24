<?php

declare(strict_types=1);

namespace App\Rules\PublicApi;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use DateTimeZone;
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
 * `CarbonImmutable::createFromFormat()` — not `strtotime()` — is what makes
 * this strict: unlike `strtotime()`'s best-effort guessing, it THROWS
 * `InvalidFormatException` (caught and treated as "try the next shape" by
 * `parse()` below) for ANY input that does not match one of these four
 * shapes exactly, relative phrases included.
 *
 * Every format is `!`-RESET (step 6 review follow-up, Part A item 3): a
 * leading `!` makes `createFromFormat()` start every unspecified date/time
 * component at the Unix epoch instead of silently filling it in from the
 * CURRENT system time — defensive against a future format in `FORMATS`
 * that does not fully specify every component (none of today's four leaves
 * a gap, since each names year through seconds, but the guarantee should
 * not depend on that staying true by accident).
 *
 * A trailing `Z` is parsed as UTC EXPLICITLY, via `self::UTC` as
 * `createFromFormat()`'s third (`$timezone`) argument, never the ambient
 * default timezone — `\Z` in a FORMAT string is a literal character (the
 * exact same escaping `\T` uses for the date/time separator), not a real
 * timezone token, so without an explicit third argument the parsed instant
 * silently took on `date_default_timezone_get()`'s zone instead of UTC.
 * The offset-form shapes (`P`) are unaffected either way — PHP resolves a
 * conflict between the format's own `P`/`O`/`T`/`e` token and the passed
 * `$timezone` argument in the FORMAT's favour, so passing `self::UTC`
 * uniformly for every shape is safe.
 *
 * A ROLLED-OVER calendar date (`2026-02-31`) is REJECTED, not silently
 * normalized to `2026-03-03` — `createFromFormat()` alone does not
 * validate calendar range (PHP's C implementation resolves an
 * out-of-range day/month by arithmetic overflow, the same as `strtotime()`
 * would). `parse()` below round-trips the successfully-parsed value back
 * through the SAME format string and compares it, byte for byte, against
 * the original input: a rollover changes the digits `format()` produces,
 * so the round-trip fails to match and the value is rejected.
 *
 * The round-trip comparison RIGHT-PADS `$value`'s own fractional digits to
 * six before comparing (gga finding 4) — `.u` accepts 1-6 digits on INPUT
 * (`createFromFormat()` is lenient there) but `format()` always EMITS
 * exactly six, zero-padded. Without the padding, a genuinely valid
 * 1-5-digit fraction (`.1Z`, or JavaScript's own three-digit millisecond
 * `toISOString()` output, `.123Z`) round-tripped to `.100000Z`/`.123000Z`
 * — byte-for-byte different from the original input — and was rejected as
 * if it had rolled over, exactly like a genuine rollover would be. Applied
 * ONLY to the two `.u`-bearing formats; the other two have no fractional
 * component to pad.
 */
final class Iso8601DateTime implements ValidationRule
{
    private const UTC = 'UTC';

    /**
     * @var list<string>
     */
    private const FORMATS = [
        '!Y-m-d\TH:i:sP',
        '!Y-m-d\TH:i:s.uP',
        '!Y-m-d\TH:i:s\Z',
        '!Y-m-d\TH:i:s.u\Z',
        // Colon-less numeric offset (`O`, e.g. `+0100`) — step 6 review
        // follow-up, finding 4. Not part of SPEC.md §3.2's own two
        // producer shapes, but a genuinely valid ISO 8601 offset form this
        // rule previously rejected outright (`P` requires the colon).
        // Tried AFTER the `P` shapes, never before: `createFromFormat()`
        // with `O` parses a colon-bearing offset leniently too, so if this
        // were tried first, a colon offset would round-trip through `O`'s
        // colon-less `format()` output and be rejected as a byte-for-byte
        // mismatch — `P` must get first refusal on anything it can parse.
        '!Y-m-d\TH:i:sO',
        '!Y-m-d\TH:i:s.uO',
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
                $parsed = CarbonImmutable::createFromFormat($format, $value, new DateTimeZone(self::UTC));
            } catch (InvalidFormatException) {
                continue;
            }

            if (! $parsed instanceof CarbonImmutable) {
                continue;
            }

            // Round-trip rejection of a rolled-over calendar date (step 6
            // review follow-up, Part A item 3): `createFromFormat()` itself
            // does not validate calendar range — "2026-02-31" silently
            // overflows into "2026-03-03" rather than failing. Re-formatting
            // the parsed value through the SAME format string (the leading
            // `!` stripped, which is meaningless to `format()`) and
            // comparing it byte for byte against the original input catches
            // exactly that: a rollover changes the digits, so the two
            // strings differ and this candidate format is rejected — the
            // loop moves on to try the next shape, exactly as a genuine
            // parse mismatch does above. `self::padFraction()` (gga finding
            // 4) normalises a short fraction on `$value`'s side FIRST, so a
            // genuinely valid 1-5-digit input compares equal to `format()`'s
            // always-six-digit output instead of being rejected alongside
            // an actual rollover. `self::normalizeNegativeZeroOffset()`
            // (step 6 review follow-up, finding 4) is applied to `$value`'s
            // side FIRST for the identical reason `padFraction()` is: a
            // genuinely valid `-00:00`/`-0000` offset — the SAME instant as
            // `+00:00`/`+0000`, merely spelled with the sign PHP's own
            // `format()` never emits back — round-tripped to the positive
            // form and was rejected as if it had rolled over, exactly like
            // an actual rollover would be. Only the LITERAL zero offset is
            // touched; a genuine non-zero offset (`-01:00`) is untouched
            // and still compared byte for byte.
            if ($parsed->format(ltrim($format, '!')) !== self::normalizeNegativeZeroOffset(self::padFraction($value, $format))) {
                continue;
            }

            return $parsed;
        }

        return null;
    }

    /**
     * Right-pads `$value`'s fractional-seconds digits to six, ONLY for a
     * `.u`-bearing `$format` — see `parse()`'s own docblock for why. No-op
     * for the two formats without a fractional component, and safe to call
     * even when `$value` does not actually contain a `.` (the regex simply
     * does not match, and `$value` is returned unchanged) — `parse()` only
     * reaches this AFTER `createFromFormat()` already accepted `$value`
     * against `$format`, so a `.u` format here always means a fraction was
     * genuinely present.
     */
    private static function padFraction(string $value, string $format): string
    {
        if (! str_contains($format, '.u')) {
            return $value;
        }

        return preg_replace_callback(
            '/\.(\d{1,6})/',
            fn (array $matches): string => '.'.str_pad($matches[1], 6, '0'),
            $value,
            limit: 1,
        ) ?? $value;
    }

    /**
     * Rewrites a trailing `-00:00`/`-0000` (negative-zero offset — the same
     * instant as `+00:00`/`+0000`, offset magnitude zero having no
     * direction) to its positive form, so it compares equal to
     * `format()`'s output, which PHP always emits with a `+` sign for a
     * zero offset regardless of how the input spelled it. Anchored to the
     * end of the string and matched on the LITERAL `00:00`/`0000` digits
     * only — a genuine non-zero negative offset (`-01:00`) never matches
     * and is left untouched, since that is a real, different offset PHP's
     * own `format()` round-trips unchanged.
     */
    private static function normalizeNegativeZeroOffset(string $value): string
    {
        return preg_replace_callback(
            '/-00:?00$/',
            fn (array $matches): string => str_contains($matches[0], ':') ? '+00:00' : '+0000',
            $value,
        ) ?? $value;
    }
}
