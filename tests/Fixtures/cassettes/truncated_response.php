<?php

declare(strict_types=1);

/**
 * Truncated-response cassette fixture (C13, scoring-failure-containment A1.11).
 *
 * Shape modeled on a REAL Anthropic max_tokens cutoff: valid JSON up to the
 * point the token budget ran out, then severed mid-string — not a hand-cut
 * placeholder. `finish_reason` is the raw provider value
 * (`AnthropicLLMProvider::parseResponse()` maps this to `LLMResponse::$truncated`
 * via `stop_reason === 'max_tokens'`, D3).
 *
 * This body is NEVER passed to `json_decode()` in the code path under test —
 * D4's short-circuit happens BEFORE any parse attempt — but it is shaped as a
 * genuine truncation so a reader can see why the short-circuit exists.
 *
 * @return array{content: string, finish_reason: string}
 */
return [
    'content' => <<<'JSON'
        {"behaviors": [{"indicator": "Work effectively with others", "score": 5, "explanation": "The candidate demonstrated e
        JSON,
    'finish_reason' => 'max_tokens',
];
