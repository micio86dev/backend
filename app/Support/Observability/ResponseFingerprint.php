<?php

declare(strict_types=1);

namespace App\Support\Observability;

use App\Services\Scoring\ResponseEnvelopeStripper;

/**
 * A DERIVED-SIGNALS-ONLY diagnostic fingerprint of a provider response
 * (C13, scoring-failure-containment D6).
 *
 * Cannot hold response text BY THE TYPES OF ITS PROPERTIES: `int` and
 * `bool` carry no substring at all, and the one text-capable property is
 * fixed at 64 lowercase-hex characters — no fragment of a scoring response
 * satisfies that shape. The Postgres CHECK constraint on
 * `ai_requests.response_sha256` is the same guarantee at the DB layer.
 *
 * Uses `ResponseEnvelopeStripper` for `fenced` so "fenced" has exactly one
 * definition in the codebase — shared with `EvaluationParser::parse()`.
 *
 * Grain (0.6/A3.6): this fingerprint is CALL-grain — one row per
 * `ai_requests` INSERT, written for every scoring call, success or failure.
 * It is a DIFFERENT grain from `CompetencyResult.unscorable_reason`
 * (competency-grain, after ALL attempts) and `IndicatorScore`'s future
 * per-indicator reason (Increment B) — a reader must not conflate "this
 * call's diagnostic signature" with "why this competency was not scored".
 */
final readonly class ResponseFingerprint
{
    private function __construct(
        public int $bytes,
        public bool $fenced,
        public string $sha256,
    ) {}

    public static function from(string $content, ResponseEnvelopeStripper $stripper): self
    {
        return new self(
            bytes: strlen($content),
            fenced: $stripper->unwrap($content)->wasFenced,
            sha256: hash('sha256', $content),
        );
    }
}
