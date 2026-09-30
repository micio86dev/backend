<?php

declare(strict_types=1);

namespace App\Support\Participant;

use Illuminate\Support\Str;

/**
 * The optional external reference a calling system stamps on a candidate
 * enrolment (candidate-external-reference): `external_id`, the calling
 * system's own integer id for the candidate, and `source`, the name of the
 * system it came from. Both are nullable and independent — an enrolment may
 * carry either, both, or neither.
 *
 * One value object owns everything the write surfaces would otherwise each
 * re-derive: the validation rules (spread into an existing `validate()` call
 * so Scramble still derives the requestBody from it), the normalisation, the
 * JWT-claim form and the column form. Neither field is a uniqueness key: a
 * row is an enrolment, so the same pair legitimately repeats across projects
 * and organisations.
 *
 * `external_id` is capped at 2^53-1 (JS `Number.MAX_SAFE_INTEGER`): the
 * consumers of this API are JSON/TypeScript clients, and above that an
 * integer no longer round-trips through a JS number.
 */
final readonly class ExternalReference
{
    /** 2^53-1, `Number.MAX_SAFE_INTEGER`. */
    public const MAX_EXTERNAL_ID = 9007199254740991;

    public const SOURCE_MAX_LENGTH = 180;

    /**
     * Longest decimal rendering of {@see self::MAX_EXTERNAL_ID} — a cheap
     * length gate so {@see self::parseExternalIdTerm()} never casts an
     * arbitrarily long digit string.
     */
    private const MAX_EXTERNAL_ID_DIGITS = 16;

    public function __construct(
        public ?int $externalId = null,
        public ?string $source = null,
    ) {}

    /**
     * Validation rules for a JSON body, identical on every write surface.
     *
     * `integer:strict` (not plain `integer`): plain `integer` runs
     * `filter_var(..., FILTER_VALIDATE_INT)`, which accepts `true` (as 1),
     * `12.0` and `"123"` and would silently persist them. Scramble reads
     * `integer:strict` as the rule `integer`, so the exported schema stays
     * `integer` rather than degrading to a float. `sometimes` lets a caller
     * omit the field; `nullable` lets it send an explicit `null`.
     *
     * @param  string  $prefix  dotted path of the enclosing object, e.g. `candidate.`
     * @return array<string, list<string>>
     */
    public static function rules(string $prefix = ''): array
    {
        return [
            "{$prefix}external_id" => ['sometimes', 'nullable', 'integer:strict', 'min:1', 'max:'.self::MAX_EXTERNAL_ID],
            "{$prefix}source" => ['sometimes', 'nullable', 'string', 'max:'.self::SOURCE_MAX_LENGTH],
        ];
    }

    /**
     * Build from an already-validated array. The `source` is trimmed and an
     * empty or whitespace-only value becomes null; the HTTP middleware
     * (`TrimStrings` + `ConvertEmptyStringsToNull`) already does that for a
     * request, this keeps non-HTTP callers equivalent.
     *
     * @param  array<array-key, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $externalId = $validated['external_id'] ?? null;
        $source = $validated['source'] ?? null;

        return new self(
            is_int($externalId) ? $externalId : null,
            is_string($source) ? self::normaliseSource($source) : null,
        );
    }

    /**
     * Build from sso-link JWT claims. The token is HS256-signed by our own
     * minter, so a malformed claim is not an attack — but the exchange must
     * never fail a candidate over optional data, so anything outside the
     * rules above is narrowed to null instead of being refused.
     */
    public static function fromClaims(mixed $externalId, mixed $source): self
    {
        $validId = is_int($externalId) && $externalId >= 1 && $externalId <= self::MAX_EXTERNAL_ID;

        $validSource = is_string($source) ? self::normaliseSource($source) : null;

        if ($validSource !== null && mb_strlen($validSource) > self::SOURCE_MAX_LENGTH) {
            $validSource = null;
        }

        return new self($validId ? $externalId : null, $validSource);
    }

    /**
     * Interpret an admin search term as an external id. Only an all-digit
     * term that fits 1..MAX qualifies, so a non-numeric or out-of-range term
     * never reaches the BIGINT comparison (where an over-range literal would
     * be a database error, not a miss).
     */
    public static function parseExternalIdTerm(string $term): ?int
    {
        if ($term === '' || strlen($term) > self::MAX_EXTERNAL_ID_DIGITS || ! ctype_digit($term)) {
            return null;
        }

        $value = (int) $term;

        return $value >= 1 && $value <= self::MAX_EXTERNAL_ID ? $value : null;
    }

    public function isEmpty(): bool
    {
        return $this->externalId === null && $this->source === null;
    }

    /**
     * The sso-link claims: only the non-null parts, so a link minted without
     * a reference carries exactly the claims it carries today.
     *
     * @return array{external_id?: int, source?: string}
     */
    public function toClaims(): array
    {
        $claims = [];

        if ($this->externalId !== null) {
            $claims['external_id'] = $this->externalId;
        }

        if ($this->source !== null) {
            $claims['source'] = $this->source;
        }

        return $claims;
    }

    /**
     * The column form, both keys always present (null included) so a
     * `forceFill()` states the whole reference.
     *
     * @return array{external_id: int|null, source: string|null}
     */
    public function toAttributes(): array
    {
        return [
            'external_id' => $this->externalId,
            'source' => $this->source,
        ];
    }

    private static function normaliseSource(string $source): ?string
    {
        $trimmed = Str::trim($source);

        return $trimmed === '' ? null : $trimmed;
    }
}
