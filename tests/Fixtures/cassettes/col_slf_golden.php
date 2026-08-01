<?php

declare(strict_types=1);

/**
 * Golden cassette fixture for COL {5,3,3} → 3.67 and SLF {5,3,-1} → 4.0.
 *
 * Derived from docs/app_description/03-ux-reference/esempio-report-valutazione.json.
 *
 * The excerpts in each behavior MUST be verbatim substrings of the utterances
 * created in GoldenCassetteTest::createGoldenCompetency(). They are checked by
 * ExcerptValidator during the job run.
 *
 * COL behaviors: [score=5, score=3, score=3] → mean = (5+3+3)/3 = 3.666… → 3.67
 * SLF behaviors: [score=5, score=3, score=-1] → assessed {5,3} → mean = (5+3)/2 = 4.0
 *
 * Array keys are the competency code strings ('COL', 'SLF') used in the test setup.
 *
 * REQ: Golden cassette fixture (C9 D8)
 *
 * @return array<string, string>
 */
return [

    'COL' => json_encode([
        'behaviors' => [
            [
                'indicator' => 'Work effectively with others',
                'score' => 5,
                'explanation' => 'The candidate demonstrated effective collaboration.',
                'excerpts' => [
                    'I worked collaboratively on multiple projects',
                ],
            ],
            [
                'indicator' => 'Willingly help colleagues in trouble',
                'score' => 3,
                'explanation' => 'The candidate adapted to different working styles.',
                'excerpts' => [
                    'Quello che abbiamo fatto è stato di cambiare le nostre abitudini',
                ],
            ],
            [
                'indicator' => 'Demonstrate commitment to team goals',
                'score' => 3,
                'explanation' => 'The candidate demonstrated commitment to team success.',
                'excerpts' => [
                    'è stato sicuramente anche un metodo molto efficace per raggiungere gli obiettivi',
                ],
            ],
        ],
    ]),

    'SLF' => json_encode([
        'behaviors' => [
            [
                'indicator' => 'Describe products and services accurately',
                'score' => 5,
                'explanation' => 'The candidate provided a detailed description.',
                'excerpts' => [
                    'quello che ho fatto è stato veramente spiegare qual era la necessità reale',
                    'Il risultato è stato che i colleghi hanno effettivamente visto',
                ],
            ],
            [
                'indicator' => 'Link own arguments to customer needs and priorities',
                'score' => 3,
                'explanation' => 'The candidate effectively linked arguments to customer needs.',
                'excerpts' => [
                    'avevamo parlato direttamente con dei potenziali clienti',
                ],
            ],
            [
                'indicator' => 'Negotiate to reach solutions that meet the primary interests of customers',
                'score' => -1,
                'explanation' => 'No relevant negotiation example found in transcript.',
                'excerpts' => [],
            ],
        ],
    ]),

];
