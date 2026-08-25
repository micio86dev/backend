<?php

declare(strict_types=1);

namespace App\Services\Scoring;

/**
 * Strips a leading/trailing markdown fence or conversational prose wrapper
 * from an LLM response body, BEFORE `json_decode()` (C13, design.md D5).
 *
 * A pure collaborator with exactly ONE definition of "fenced" in the
 * codebase — called by `EvaluationParser::parse()` and by
 * `ResponseFingerprint::from()` (A3).
 *
 * Two rules, both narrow, applied to the TRIMMED input:
 *
 * 1. Fence — starts with ``` (optionally followed by a language tag on the
 *    same line) AND a closing ``` exists → take what is between them.
 *    `wasFenced = true`. The run AFTER the closing fence is a genuine
 *    discarded trailing run and is safety-checked exactly like rule 2's —
 *    a fence followed by trailing prose that itself contains `{`, `}` or
 *    `"` is refused, so a reader cannot construct "fence, then a smuggled
 *    brace" and have it silently discarded.
 * 2. Prose — extract from the first `{` to the last `}` — but ONLY if the
 *    discarded leading AND trailing runs contain NONE of `{`, `}`, `"`. If
 *    either run contains one of those, strip nothing and let `json_decode()`
 *    fail on the untouched body.
 *
 * THE STRIPPER NEVER VALIDATES ANYTHING. `json_decode()` remains the sole
 * acceptance test at the call site — a stripper that returns garbage
 * produces a `JsonParseException` identical to today's. There is no
 * partial-acceptance path for the tolerance to leak through: the worst case
 * of a bug here is the failure we already have, never a silent mis-parse.
 *
 * Rule 2's brace-and-quote condition exists specifically to reject a
 * TRUNCATED body containing a complete inner object followed by an
 * incomplete one — a truncated body's trailing run is full of `"` and `{`,
 * which this refuses rather than salvaging the (partial) inner object.
 */
final class ResponseEnvelopeStripper
{
    public function unwrap(string $raw): UnwrappedResponse
    {
        $trimmed = trim($raw);

        return $this->tryFence($trimmed)
            ?? $this->tryProse($trimmed)
            ?? new UnwrappedResponse(json: $trimmed, wasFenced: false);
    }

    private function tryFence(string $trimmed): ?UnwrappedResponse
    {
        if (! str_starts_with($trimmed, '```')) {
            return null;
        }

        $firstNewline = strpos($trimmed, "\n");

        if ($firstNewline === false) {
            return null;
        }

        $closingPos = strrpos($trimmed, '```');

        if ($closingPos === false || $closingPos <= $firstNewline) {
            return null;
        }

        $after = substr($trimmed, $closingPos + 3);

        if ($this->hasUnsafeChar($after)) {
            return null;
        }

        $inner = substr($trimmed, $firstNewline + 1, $closingPos - $firstNewline - 1);

        return new UnwrappedResponse(json: trim($inner), wasFenced: true);
    }

    private function tryProse(string $trimmed): ?UnwrappedResponse
    {
        $firstBrace = strpos($trimmed, '{');
        $lastBrace = strrpos($trimmed, '}');

        if ($firstBrace === false || $lastBrace === false || $lastBrace <= $firstBrace) {
            return null;
        }

        $leadingRun = substr($trimmed, 0, $firstBrace);
        $trailingRun = substr($trimmed, $lastBrace + 1);

        if ($this->hasUnsafeChar($leadingRun) || $this->hasUnsafeChar($trailingRun)) {
            return null;
        }

        return new UnwrappedResponse(
            json: substr($trimmed, $firstBrace, $lastBrace - $firstBrace + 1),
            wasFenced: false,
        );
    }

    private function hasUnsafeChar(string $discardedRun): bool
    {
        return str_contains($discardedRun, '{')
            || str_contains($discardedRun, '}')
            || str_contains($discardedRun, '"');
    }
}
