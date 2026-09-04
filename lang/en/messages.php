<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Project questions
    |--------------------------------------------------------------------------
    |
    | Operator-facing VALIDATION messages, and therefore localized — the
    | machine-facing rule (field names, enum values, HTTP status) stays
    | literal, but the sentence an operator reads in the backoffice must follow
    | the language they are working in. These two were English string literals
    | built by interpolation inside the FormRequest, so an operator on the
    | Italian backoffice was told "A standard project allows at most 1
    | question(s) per competency." in the middle of an otherwise Italian
    | screen.
    |
    */
    'project_questions' => [
        // A sentence, not a field dump: `type=:actual` read like a log line to
        // the operator who has to act on it.
        'competency_type_mismatch' => "Competency ':code' is a :actual competency; this project requires a :expected one.",
        // trans_choice, not an interpolated ":max question(s)". The cap is 1 in
        // the overwhelmingly common case, and "1 questions" is wrong in English
        // and ungrammatical in Italian, which has no "(s)" escape hatch.
        'max_per_competency' => '{1} A :type assessment allows at most one question per competency.|[2,*] A :type assessment allows at most :count questions per competency.',
        // The assessment TYPE, translated. `:type` used to receive the raw
        // enum, so an Italian operator read "Un progetto standard…" with an
        // untranslated token mid-sentence.
        'assessment_type' => [
            'standard' => 'standard',
            'potential' => 'potential',
        ],
    ],

    'welcome' => 'Welcome',
];
