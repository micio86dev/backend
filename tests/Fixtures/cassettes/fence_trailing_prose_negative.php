<?php

declare(strict_types=1);

/**
 * Negative cassette fixture: fence + trailing prose containing a brace
 * (C13, scoring-failure-containment A2.4, design.md D5).
 *
 * The cleanly-fenced JSON is followed by prose that itself contains a `{`
 * after the closing fence — the discarded trailing run is unsafe, so
 * `ResponseEnvelopeStripper` MUST refuse (or, if the fallback prose rule
 * extracts a garbage span instead, `json_decode()` must still fail on it).
 * Either way this MUST still raise `JsonParseException` — it must NEVER
 * silently return the clean fenced JSON while discarding a brace.
 *
 * @return array{content: string}
 */
return [
    'content' => <<<'TEXT'
        ```json
        {"behaviors": [{"indicator": "Work effectively with others", "score": 5, "explanation": "Strong evidence of collaboration.", "excerpts": ["I worked collaboratively on multiple projects"]}]}
        ```
        Note: the model considered {other approaches} too.
        TEXT,
];
