<?php

declare(strict_types=1);

/**
 * Negative cassette fixture: a plausible-looking-but-malformed body
 * (C13, scoring-failure-containment A2.4, design.md D5).
 *
 * Already starts with `{` and ends with `}` — the stripper is a no-op (both
 * discarded runs are empty, hence trivially safe) — but the JSON itself is
 * malformed (a stray trailing comma before the closing bracket). This MUST
 * still raise `JsonParseException`: the tolerance pass MUST NOT become a
 * general "find some JSON in there" salvage routine.
 *
 * @return array{content: string}
 */
return [
    'content' => '{"behaviors": [{"indicator": "Work effectively with others", "score": 5, "explanation": "Strong evidence of collaboration.", "excerpts": ["I worked collaboratively on multiple projects"],}]}',
];
