<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Support\Participant\ExternalReference;

/**
 * Shared datasets for the candidate external reference (`external_id` +
 * `source`) tests, so every write surface is exercised with the SAME
 * accepted and refused values rather than each test file re-typing its own
 * idea of "invalid". Class-based (not `tests/Datasets`, which this repo does
 * not have) and autoloaded through the `Tests\` PSR-4 root.
 *
 * Use with `->with(ExternalReferenceCases::invalid())`.
 */
final class ExternalReferenceCases
{
    /**
     * Values refused on a JSON body, keyed by a readable description.
     * Each entry is `[field, value]`.
     *
     * `"12"`, `12.5` and `true` are the `integer:strict` cases: plain
     * `integer` would coerce all three (`filter_var(true, FILTER_VALIDATE_INT)`
     * is `1`), so each one pins that the strict rule is in place. The two
     * boundary entries sit exactly one past the accepted range.
     *
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function invalid(): array
    {
        return [
            'numeric string external_id' => ['external_id', '12'],
            'float external_id' => ['external_id', 12.5],
            'boolean external_id' => ['external_id', true],
            'zero external_id' => ['external_id', 0],
            'negative external_id' => ['external_id', -1],
            'external_id above the safe-integer cap' => ['external_id', ExternalReference::MAX_EXTERNAL_ID + 1],
            'source over the length cap' => ['source', str_repeat('a', ExternalReference::SOURCE_MAX_LENGTH + 1)],
        ];
    }

    /**
     * The four legitimate shapes of the optional pair, keyed by a readable
     * description. Each entry is `[externalId, source]`.
     *
     * @return array<string, array{0: int|null, 1: string|null}>
     */
    public static function validCombinations(): array
    {
        return [
            'both' => [4471, 'acme-ats'],
            'only external_id' => [4471, null],
            'only source' => [null, 'acme-ats'],
            'neither' => [null, null],
        ];
    }
}
