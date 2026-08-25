<?php

declare(strict_types=1);

namespace App\Services\Scoring;

/**
 * Result of `ResponseEnvelopeStripper::unwrap()` (C13, design.md D5).
 *
 * `$json` is NOT validated — `json_decode()` remains the sole acceptance
 * test at the call site. `$wasFenced` is a diagnostic signal only, also
 * consumed by `ResponseFingerprint` (A3) so "fenced" has exactly one
 * definition in the codebase.
 */
final readonly class UnwrappedResponse
{
    public function __construct(
        public string $json,
        public bool $wasFenced,
    ) {}
}
