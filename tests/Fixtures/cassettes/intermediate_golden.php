<?php

declare(strict_types=1);

/**
 * Golden cassette fixture — residual score levels end-to-end (bars-full-scale-1-5, D9).
 *
 * Three competencies exercising the widened {1,2,3,4,5,-1} domain through the full
 * pipeline (parse → validate → persist → mean → serialize):
 *
 *   INTA behaviors: [score=4, score=2, score=3] → mean = (4+2+3)/3 = 3.00, reliability = 3/3 = 1.0
 *   INTB behaviors: [score=5, score=4, score=-1] → assessed {5,4} → mean = (5+4)/2 = 4.50, reliability = 2/3
 *   INTC behaviors: [score=2, score=3, score=4, score=5] → mean = (2+3+4+5)/4 = 3.50 (D9 boundary case,
 *       exactly on the mean-chip boundary — pins AD-4's "3.5 → warning" assumption on the API side)
 *
 * Excerpts MUST be verbatim substrings of the utterances created in
 * IntermediateScaleCassetteTest::intermediateSetupCompetency(). Checked by ExcerptValidator
 * during the job run. Explanations for residual scores (4, 2) name both bounding anchors
 * per D5's tightened explanation contract.
 *
 * Array keys are the competency code strings ('INTA', 'INTB', 'INTC') used in the test setup.
 *
 * REQ: Golden cassette — residual levels (bars-full-scale-1-5 D9)
 *
 * @return array<string, string>
 */
return [

    'INTA' => json_encode([
        'behaviors' => [
            [
                'indicator' => 'Adapt communication style to the audience',
                'score' => 4,
                'explanation' => 'Clearly exceeds the Score 3 anchor (adequate adaptation) but does not fully match the Score 5 anchor (consistently tailors register); a residual level between the two.',
                'excerpts' => [
                    'I adjusted my explanation once I noticed the client looked confused',
                ],
            ],
            [
                'indicator' => 'Structure information logically',
                'score' => 2,
                'explanation' => 'Clearly below the Score 3 anchor (logical structure) but not as weak as the Score 1 anchor (no discernible structure); the account wandered but was not incoherent.',
                'excerpts' => [
                    'I kind of jumped between topics but eventually got to the point',
                ],
            ],
            [
                'indicator' => 'Check for mutual understanding',
                'score' => 3,
                'explanation' => 'Matches the Score 3 anchor: asked one clarifying question.',
                'excerpts' => [
                    'I asked if that made sense before moving on',
                ],
            ],
        ],
    ]),

    'INTB' => json_encode([
        'behaviors' => [
            [
                'indicator' => 'Take initiative without being asked',
                'score' => 5,
                'explanation' => 'Matches the Score 5 anchor: proposed and executed a solution unprompted.',
                'excerpts' => [
                    'nobody asked me to but I went ahead and fixed the report template myself',
                ],
            ],
            [
                'indicator' => 'Anticipate downstream problems',
                'score' => 4,
                'explanation' => 'Clearly exceeds the Score 3 anchor (spots the immediate issue) but does not fully match the Score 5 anchor (systematic foresight across the whole pipeline); a residual level between the two.',
                'excerpts' => [
                    'I flagged that the deadline would slip before anyone else noticed',
                ],
            ],
            [
                'indicator' => 'Push back on unrealistic deadlines',
                'score' => -1,
                'explanation' => 'No relevant example found in transcript.',
                'excerpts' => [],
            ],
        ],
    ]),

    'INTC' => json_encode([
        'behaviors' => [
            [
                'indicator' => 'Give constructive feedback',
                'score' => 2,
                'explanation' => 'Clearly below the Score 3 anchor (specific, actionable feedback) but not as weak as the Score 1 anchor (no feedback given); the comment was vague but present.',
                'excerpts' => [
                    'I told them it could be better without saying exactly how',
                ],
            ],
            [
                'indicator' => 'Receive feedback without defensiveness',
                'score' => 3,
                'explanation' => 'Matches the Score 3 anchor: acknowledged the feedback and made one change.',
                'excerpts' => [
                    'I took the note and reworked the section they mentioned',
                ],
            ],
            [
                'indicator' => 'Follow up on agreed action items',
                'score' => 4,
                'explanation' => 'Clearly exceeds the Score 3 anchor (follows up when reminded) but does not fully match the Score 5 anchor (proactively tracks every item); a residual level between the two.',
                'excerpts' => [
                    'I checked back on it two days later on my own',
                ],
            ],
            [
                'indicator' => 'Document decisions for the team',
                'score' => 5,
                'explanation' => 'Matches the Score 5 anchor: wrote up the decision and shared it immediately.',
                'excerpts' => [
                    'I wrote a short summary and sent it to the whole team the same afternoon',
                ],
            ],
        ],
    ]),

];
