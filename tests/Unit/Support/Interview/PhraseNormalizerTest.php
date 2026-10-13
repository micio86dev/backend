<?php

declare(strict_types=1);

use App\Rules\NoCompletionPhrase;
use App\Support\Interview\PhraseNormalizer;
use Illuminate\Support\Facades\Validator;

/**
 * Pins equivalence with `normalizePhrase` in `frontend/app/utils/proctor-config.ts`:
 * lowercase, NFD, strip combining marks, every non [a-z0-9] becomes a space,
 * collapse whitespace, trim. The client decides "the interview is over" with that
 * function, so the intro guard must agree with it character for character.
 */
test('it normalises exactly like the client', function (string $input, string $expected): void {
    expect(PhraseNormalizer::normalize($input))->toBe($expected);
})->with([
    'case' => ['Thank You For Your TIME', 'thank you for your time'],
    'punctuation' => ["Let's move on, to the next question!", 'let s move on to the next question'],
    'accents are stripped without a space' => ['Più perché già', 'piu perche gia'],
    'precomposed and decomposed forms agree' => ["e\u{0301}", 'e'],
    'whitespace is collapsed and trimmed' => ["  a \t\n b  ", 'a b'],
    'non latin letters become spaces' => ['ab 日本 cd', 'ab cd'],
    'digits are kept' => ['Step 2: go', 'step 2 go'],
    'empty' => ['', ''],
]);

test('the forbidden list holds the end and final phrase of every locale, normalised', function (): void {
    expect(PhraseNormalizer::completionPhrases())->toEqualCanonicalizing([
        'let s move on to the next question',
        'thank you for your time',
        'passiamo alla prossima domanda',
        'grazie per il tuo tempo',
    ]);
});

test('the rule rejects an intro containing a completion phrase, ignoring accents, case and punctuation', function (string $intro, bool $passes): void {
    $validator = Validator::make(['v' => $intro], ['v' => [new NoCompletionPhrase]]);

    expect($validator->passes())->toBe($passes);
})->with([
    'clean' => ['Welcome to BEAI. Speak freely.', true],
    'english final phrase' => ['Hello. THANK YOU, for your time!', false],
    'english end phrase' => ['Hi. Lets move on to the next question.', true],
    'english end phrase with apostrophe' => ["Hi. Let's move on to the next question.", false],
    'italian end phrase' => ['Ciao, passiamo alla PROSSIMA domanda', false],
    'italian final phrase' => ['Grazie per il tuo tempo.', false],
    'italian phrase in an english intro' => ['Please relax. Passiamo alla prossima domanda.', false],
]);

test('the rule ignores non string values', function (): void {
    expect(Validator::make(['v' => 5], ['v' => [new NoCompletionPhrase]])->passes())->toBeTrue();
});

test('a blank completion phrase in a lang file never refuses every intro', function (): void {
    app('translator')->addLines(['interview.end_phrase' => '  '], 'it');

    expect(PhraseNormalizer::completionPhrases())->not->toContain('')
        ->and(Validator::make(['v' => 'Welcome.'], ['v' => [new NoCompletionPhrase]])->passes())->toBeTrue();
});

test('a missing completion phrase key never becomes a forbidden phrase', function (): void {
    app('translator')->addLines(['interview.end_phrase' => 'interview.end_phrase'], 'it');

    // Laravel returns the key itself for an untranslated line.
    expect(PhraseNormalizer::completionPhrases())->not->toContain('interview end phrase')
        ->and(Validator::make(['v' => 'Interview end phrase'], ['v' => [new NoCompletionPhrase]])->passes())->toBeTrue();
});
