<?php

declare(strict_types=1);

namespace App\Exceptions\Scoring;

/**
 * Thrown when an excerpt is not a verbatim (whitespace-normalized) substring
 * of the assembled session transcript.
 *
 * Triggers unscorable_reason = 'llm_parse_error' (FIX-9).
 * The caller marks the competency unscorable.
 *
 * REQ: ExcerptValidator — verbatim substring check (C9 D4 CW2)
 */
final class ExcerptNotVerbatimException extends \RuntimeException
{
    public function __construct(string $excerpt, int $position = -1)
    {
        $ctx = $position >= 0 ? " at indicator position {$position}" : '';
        $preview = mb_substr($excerpt, 0, 60);
        parent::__construct(
            "Excerpt{$ctx} is not a verbatim substring of the transcript: \"{$preview}\"."
        );
    }
}
