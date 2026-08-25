<?php

declare(strict_types=1);

namespace App\Testing;

/**
 * One cassette-provider response, with an explicit `finishReason` override
 * (C13, scoring-failure-containment D8).
 *
 * The bare-`string` cassette entry (`CassetteLLMProvider`'s original shape)
 * cannot vary `finishReason` per call, so it cannot express "truncated then
 * complete" — exactly what the A1 truncated cassette and the B1 truncation-
 * retry test both need. `CassetteResponse` is the smallest unit that can: one
 * call, one body, one `finishReason`.
 */
final readonly class CassetteResponse
{
    public function __construct(
        public string $content,
        public string $finishReason = 'stop',
    ) {}
}
