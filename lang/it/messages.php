<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Domande di progetto
    |--------------------------------------------------------------------------
    */
    'project_questions' => [
        // The sentence must hold with BOTH forms `assessment_type` can return
        // here — 'standard' and 'di potenziale'. "è di tipo :actual" produced
        // "è di tipo di potenziale", with the preposition doubled.
        'competency_type_mismatch' => "La competenza ':code' è :actual; questo progetto richiede una valutazione :expected.",
        'max_per_competency' => '{1} Una valutazione :type consente al massimo una domanda per competenza.|[2,*] Una valutazione :type consente al massimo :count domande per competenza.',
        'assessment_type' => [
            'standard' => 'standard',
            'potential' => 'di potenziale',
        ],
    ],

    'welcome' => 'Benvenuto',
];
