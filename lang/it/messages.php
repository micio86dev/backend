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
        // here — 'di Prontezza' and 'di Potenziale'. The machine value stays
        // `standard`; only the displayed name is Prontezza. "è di tipo :actual" produced
        // "è di tipo di potenziale", with the preposition doubled.
        'competency_type_mismatch' => "La competenza ':code' è :actual; questo progetto richiede una valutazione :expected.",
        'max_per_competency' => '{1} Una valutazione :type consente al massimo una domanda per competenza.|[2,*] Una valutazione :type consente al massimo :count domande per competenza.',
        'assessment_type' => [
            'standard' => 'di Prontezza',
            'potential' => 'di Potenziale',
        ],
    ],

    'avatar_templates' => [
        'provider_immutable' => 'Il provider non può essere cambiato dopo la creazione. Crea invece un nuovo template.',
        'answer_range' => 'Il tempo massimo di risposta deve essere maggiore del minimo.',
        'name_taken' => 'Esiste già un template con questo nome.',
        'persona_key' => 'La persona contiene una chiave non accettata.',
        'activation_race' => 'Un altro template di questo provider è stato attivato nello stesso momento. Ricarica e riprova.',
    ],

    'welcome' => 'Benvenuto',
];
