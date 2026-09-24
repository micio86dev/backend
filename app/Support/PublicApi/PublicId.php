<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Encodes/decodes the BEAI Public API (`/v1`) prefixed external id — G-05,
 * `public-api/openapi.yaml`'s per-resource id pattern
 * (`^prj_[0-9A-HJKMNP-TV-Z]{26}$` etc. — uppercase Crockford base32,
 * `Str::ulid()`'s own rendering).
 *
 * The prefix (`org_`, `prj_`, …) is NEVER stored — see
 * `App\Models\Concerns\HasPublicId`'s own docblock — so this class is the
 * ONLY place that joins a bare ULID to its resource's prefix, in either
 * direction. No public response may build a `{prefix}{ulid}` string any
 * other way, and no route handler may compare a caller-supplied id against
 * the `public_id` column without going through `decode()` first — a
 * mismatched prefix on an otherwise well-formed ULID must never silently
 * match another resource type's row.
 */
final class PublicId
{
    /**
     * `^[0-9A-HJKMNP-TV-Z]{26}$` — Crockford base32, excluding I/L/O/U,
     * uppercase only, exactly 26 characters (a ULID's fixed length).
     */
    private const ULID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    /**
     * @param  Model&PubliclyIdentifiable  $model
     *
     * @throws LogicException when `$model->public_id` is empty — meaning
     *                        the model was never saved through `HasPublicId`'s `creating` hook (a
     *                        caller trying to encode an unpersisted, not-yet-minted instance is a
     *                        programming error, not a runtime edge case to paper over).
     */
    public static function encode(Model $model): string
    {
        $bareId = $model->getAttribute('public_id');

        if (! is_string($bareId) || $bareId === '') {
            throw new LogicException(sprintf(
                '%s: cannot encode a public id — public_id is empty. Was this model saved?',
                $model::class,
            ));
        }

        return $model::publicIdPrefix().$bareId;
    }

    /**
     * @return string|null the BARE ulid (no prefix) when `$value` starts
     *                     with `$expectedPrefix` and the remainder is a syntactically valid ULID;
     *                     `null` on any mismatch — wrong prefix, wrong length, or a character
     *                     outside the Crockford base32 alphabet. Never throws: every caller treats
     *                     `null` as "this id cannot possibly resolve to a row", which is always a
     *                     `404`, never a `400` (SPEC.md §3.2).
     */
    public static function decode(string $value, string $expectedPrefix): ?string
    {
        if (! str_starts_with($value, $expectedPrefix)) {
            return null;
        }

        $remainder = substr($value, strlen($expectedPrefix));

        if (preg_match(self::ULID_PATTERN, $remainder) !== 1) {
            return null;
        }

        return $remainder;
    }
}
