<?php

declare(strict_types=1);

/**
 * Fenced-response cassette fixture (C13, scoring-failure-containment A2.4).
 *
 * A markdown-fenced JSON body — the shape a model actually produces when it
 * wraps its answer in ```json ... ``` despite instructions not to. Designed
 * for `tests/Helpers/ScoringFixtures.php::setupScoringCompetency()`'s single
 * indicator ('Work effectively with others') and utterance ('I worked
 * collaboratively on multiple projects.') — the excerpt below is a verbatim
 * substring of that utterance.
 *
 * @return array{content: string}
 */
return [
    'content' => <<<'TEXT'
        ```json
        {"behaviors": [{"indicator": "Work effectively with others", "score": 5, "explanation": "Strong evidence of collaboration.", "excerpts": ["I worked collaboratively on multiple projects"]}]}
        ```
        TEXT,
];
