<?php

declare(strict_types=1);

/**
 * `OpeningTextComposer` builds the avatar's spoken opening line. It is a
 * SIBLING of `SystemPromptComposer`, never inside it, and never touches BARS
 * indicator/anchor content (anti-leak invariant).
 *
 * Asserts:
 * - An authored primary is the opening, verbatim, for first/next/resume.
 * - `retry` wraps it (or the fallback) in the apology.
 * - With no authored primary (interviewability gate off), every variant uses
 *   the ONE gate-off fallback, `interview.opening.fallback`.
 * - Determinism, version stamping, locale fallback, unknown variant → throw.
 */

use App\DTOs\Conversation\ComposedOpening;
use App\Exceptions\Conversation\CompositionException;
use App\Services\Conversation\OpeningTextComposer;

test('without an authored question, compose() returns the gate-off fallback with competency interpolated', function (): void {
    $composer = new OpeningTextComposer;

    $result = $composer->compose('first', 'Problem Solving', 'en');

    expect($result)->toBeInstanceOf(ComposedOpening::class);
    expect($result->text)->toBe(trans('interview.opening.fallback', ['competency' => 'Problem Solving'], 'en'));
    expect($result->text)->toContain('Problem Solving');
});

test('compose() is deterministic — same inputs produce the same text and version', function (): void {
    $composer = new OpeningTextComposer;

    $a = $composer->compose('next', 'Collaboration', 'it');
    $b = $composer->compose('next', 'Collaboration', 'it');

    expect($a->text)->toBe($b->text);
    expect($a->version)->toBe($b->version);
});

test('compose() version equals config(conversation.prompt_version) — shared with the system prompt', function (): void {
    config(['conversation.prompt_version' => 'conv-test-999']);

    $composer = new OpeningTextComposer;
    $result = $composer->compose('first', 'Judgement', 'en');

    expect($result->version)->toBe('conv-test-999');
});

test('without an authored question, first/next/resume all use the same fallback', function (): void {
    $composer = new OpeningTextComposer;

    $first = $composer->compose('first', 'Drive', 'en')->text;

    expect($composer->compose('next', 'Drive', 'en')->text)->toBe($first);
    expect($composer->compose('resume', 'Drive', 'en')->text)->toBe($first);
});

test('compose() falls back to the platform default locale when the requested locale has no interview.php file', function (): void {
    $composer = new OpeningTextComposer;

    $result = $composer->compose('first', 'Insight', 'fr');

    $fallback = (string) config('app.fallback_locale');
    expect($result->text)->toBe(trans('interview.opening.fallback', ['competency' => 'Insight'], $fallback));
});

test('compose() never reaches BARS anchor/indicator text — output is exactly the interpolated template (anti-leak)', function (): void {
    $composer = new OpeningTextComposer;

    // A competency name containing no BARS-like content; the composer has no
    // dependency capable of injecting anchor/indicator text (no BarsIndicatorLoader,
    // no BARS model access at all) — the anti-leak guarantee holds by construction.
    $result = $composer->compose('first', 'Strategic Thinking', 'en');

    expect($result->text)->not->toContain('Excellent:');
    expect($result->text)->not->toContain('Adequate:');
    expect($result->text)->not->toContain('Insufficient:');
    expect($result->text)->not->toContain('COVERAGE TOPICS');
});

test('compose() with an unknown variant throws InvalidArgumentException (fail loud, no silent default)', function (): void {
    $composer = new OpeningTextComposer;

    expect(fn () => $composer->compose('bogus_variant', 'Networking', 'en'))
        ->toThrow(InvalidArgumentException::class);
});

// ─── 'retry' (interview-continuous-flow, D10) ─────────────────────────────────
//
// A competency that ended in `error` is offered to the candidate again. The
// apology explains a failure on OUR side; without it the repeat reads as not
// having been listened to.

test("compose('retry') without an authored question apologises, then asks the fallback", function (): void {
    $composer = new OpeningTextComposer;

    $retry = $composer->compose('retry', 'Networking', 'it')->text;
    $fallback = trans('interview.opening.fallback', ['competency' => 'Networking'], 'it');

    expect($retry)->not->toBe($fallback)
        ->and($retry)->toContain('problema tecnico')
        ->and($retry)->toEndWith($fallback);
});

test("compose('retry') interpolates the competency name in both locales", function (): void {
    $composer = new OpeningTextComposer;

    expect($composer->compose('retry', 'Networking', 'it')->text)->toContain('Networking');
    expect($composer->compose('retry', 'Networking', 'en')->text)->toContain('Networking');
});

test("compose('retry') says something happened, without blaming the candidate", function (): void {
    // The re-offer exists because a provider call failed on OUR side. The
    // greeting must not imply the candidate answered badly or ran out of time.
    $composer = new OpeningTextComposer;

    foreach (['it', 'en'] as $locale) {
        $text = mb_strtolower($composer->compose('retry', 'Networking', $locale)->text);

        foreach (['sbagli', 'errore tuo', 'non hai', 'your mistake', 'you failed', 'incorrect'] as $blame) {
            expect($text)->not->toContain($blame);
        }
    }
});

test("compose('retry') falls back to the default locale for an unknown one", function (): void {
    $composer = new OpeningTextComposer;

    expect($composer->compose('retry', 'Networking', 'xx')->text)->toContain('Networking');
});

/**
 * RED — authored questions open the competency (image/interview follow-up,
 * reported 2026-09-08).
 *
 * An operator authored questions for a competency, and the avatar opened with
 * "Parliamo di problem solving… raccontami un episodio" — a template sentence
 * they never wrote. Their questions WERE reaching the system prompt as
 * mandatory, but the spoken opening already asked a generic question first, so
 * the first thing a candidate ever heard was never the operator's.
 *
 * Ratified 2026-09-08: when a competency has an authored question, that
 * question IS the opening, verbatim, with no greeting wrapped around it.
 */
test('an authored question REPLACES the opening template entirely, verbatim', function (): void {
    $composer = new OpeningTextComposer;

    $result = $composer->compose('first', 'Problem Solving', 'it', 'Raccontami di una volta in cui hai gestito un cliente ostile.');

    expect($result->text)->toBe('Raccontami di una volta in cui hai gestito un cliente ostile.');
    // No welcome, no "parliamo di", no competency name bolted on.
    expect($result->text)->not->toContain('Problem Solving');
    expect($result->text)->not->toContain('benvenuto');
});

test('the same replacement applies to a subsequent competency', function (): void {
    $composer = new OpeningTextComposer;

    $result = $composer->compose('next', 'Collaboration', 'it', 'Parlami di un conflitto in team.');

    expect($result->text)->toBe('Parlami di un conflitto in team.');
});

test('a RESUME re-asks the authored question it is given, verbatim', function (): void {
    // The controller passes the pending primary (or the last one when every
    // primary was already asked); the opening is that question as written.
    $composer = new OpeningTextComposer;

    $result = $composer->compose('resume', 'Collaboration', 'it', 'Parlami di un conflitto in team.');

    expect($result->text)->toBe('Parlami di un conflitto in team.');
});

test('a RETRY keeps its apology and ends on the authored question', function (): void {
    // The apology is not a greeting: the candidate just hit a failure on OUR
    // side, and dropping the explanation makes the repeat read as not having
    // been heard.
    $composer = new OpeningTextComposer;

    $result = $composer->compose('retry', 'Collaboration', 'it', 'Parlami di un conflitto in team.');

    expect($result->text)->toContain('problema tecnico');
    expect($result->text)->toEndWith('Parlami di un conflitto in team.');
});

test('a blank authored question falls back to the template rather than opening on silence', function (): void {
    $composer = new OpeningTextComposer;

    $result = $composer->compose('first', 'Problem Solving', 'it', '   ');

    expect($result->text)->toBe(trans('interview.opening.fallback', ['competency' => 'Problem Solving'], 'it'));
});

test('the version is stamped identically whether or not a question was authored', function (): void {
    $composer = new OpeningTextComposer;

    expect($composer->compose('first', 'X', 'it', 'Domanda mia.')->version)
        ->toBe($composer->compose('first', 'X', 'it')->version);
});

/**
 * The ENGLISH `retry_authored`, which is not merely one more locale.
 *
 * `en` is `app.fallback_locale`, so it is what every project in a language
 * without its own phrase file receives — fr, de, es, pt today, and whatever
 * comes next. Only the Italian string was asserted, so a typo'd `:question`
 * in `lang/en/interview.php` would leave this suite green while the avatar
 * read a raw placeholder aloud to every non-Italian candidate.
 */
test('the retry apology substitutes :question in the fallback locale too', function (): void {
    $composer = new OpeningTextComposer;

    $result = $composer->compose('retry', 'Collaboration', 'en', 'Tell me about a team conflict.');

    expect($result->text)->toEndWith('Tell me about a team conflict.');
    expect($result->text)->not->toContain(':question');
});

test('an unknown locale falls back to English for the authored retry, not to a placeholder', function (): void {
    // The fallback path is the one no project exercises directly and every
    // future locale lands on.
    $composer = new OpeningTextComposer;

    $result = $composer->compose('retry', 'Collaboration', 'pt', 'Conte-me sobre um conflito.');

    expect($result->text)->toBe(
        trans('interview.opening.retry_authored', ['question' => 'Conte-me sobre um conflito.'], 'en')
    );
    expect($result->text)->not->toContain(':question');
});

test('a blank conversation.prompt_version is refused on the authored and the fallback path alike', function (): void {
    config(['conversation.prompt_version' => '  ']);
    $composer = new OpeningTextComposer;

    expect(fn () => $composer->compose('first', 'X', 'en', 'Authored?'))->toThrow(CompositionException::class)
        ->and(fn () => $composer->compose('first', 'X', 'en'))->toThrow(CompositionException::class);
});

// ─── 'reinterview' (scoring-retry-rt-b, PR2c) ────────────────────────────────
//
// A competency asked again because an evaluation retry reset it. Nothing broke
// and the candidate did nothing wrong, so unlike `retry` there is no apology;
// unlike `first` it must not read as a first-time greeting.

test("compose('reinterview') continues the interview and ends on the authored question, in both locales", function (string $locale, string $question): void {
    $composer = new OpeningTextComposer;

    $text = $composer->compose('reinterview', 'Networking', $locale, $question)->text;
    $template = (string) trans('interview.opening.reinterview_authored', ['question' => $question], $locale);

    expect($text)->toBe($template)
        ->and($text)->not->toBe($question)
        ->and($text)->toEndWith($question)
        ->and($text)->not->toContain(':question');
})->with([
    'it' => ['it', 'Parlami di un conflitto in team.'],
    'en' => ['en', 'Tell me about a team conflict.'],
]);

test("compose('reinterview') says we continue with the remaining topics, in the language of the project", function (): void {
    $composer = new OpeningTextComposer;

    expect($composer->compose('reinterview', 'X', 'it', 'Q?')->text)->toContain('argomenti')
        ->and($composer->compose('reinterview', 'X', 'en', 'Q?')->text)->toContain('remaining topics');
});

test("compose('reinterview') neither apologises nor mentions scores, results or failures", function (): void {
    $composer = new OpeningTextComposer;

    foreach (['it', 'en'] as $locale) {
        $text = mb_strtolower($composer->compose('reinterview', 'Networking', $locale, 'Q?')->text);

        foreach (['scus', 'sorry', 'problema', 'technical', 'errore', 'error', 'punteggi', 'score', 'risultat', 'result', 'valut', 'evaluat', 'invalid', 'non valid', 'failed', 'fallit', 'sbagli', 'incorrect', 'again', 'da capo', 'start over'] as $forbidden) {
            expect($text)->not->toContain($forbidden);
        }
    }
});

test("compose('reinterview') differs from retry and does not read as a first-time greeting", function (): void {
    $composer = new OpeningTextComposer;

    foreach (['it', 'en'] as $locale) {
        $reinterview = $composer->compose('reinterview', 'X', $locale, 'Q?')->text;

        expect($reinterview)->not->toBe($composer->compose('retry', 'X', $locale, 'Q?')->text)
            ->and($reinterview)->not->toBe($composer->compose('first', 'X', $locale, 'Q?')->text)
            ->and(mb_strtolower($reinterview))->not->toContain('benvenut')
            ->and(mb_strtolower($reinterview))->not->toContain('welcome');
    }
});

test("compose('reinterview') without an authored question ends on the gate-off fallback", function (): void {
    $composer = new OpeningTextComposer;
    $fallback = trans('interview.opening.fallback', ['competency' => 'Networking'], 'en');

    $text = $composer->compose('reinterview', 'Networking', 'en')->text;

    expect($text)->toEndWith($fallback)
        ->and($text)->not->toBe($fallback);
});

test("compose('reinterview') falls back to English for a locale without its own phrase file", function (): void {
    $composer = new OpeningTextComposer;

    $text = $composer->compose('reinterview', 'X', 'pt', 'Conte-me sobre um conflito.')->text;

    expect($text)->toBe(trans('interview.opening.reinterview_authored', ['question' => 'Conte-me sobre um conflito.'], 'en'))
        ->and($text)->not->toContain(':question');
});

test("compose('reinterview') carries the shared prompt_version and contains no BARS anchor text", function (): void {
    config(['conversation.prompt_version' => 'conv-test-reinterview']);
    $composer = new OpeningTextComposer;

    $result = $composer->compose('reinterview', 'Strategic Thinking', 'en', 'Q?');

    expect($result->version)->toBe('conv-test-reinterview')
        ->and($result->text)->not->toContain('Excellent:')
        ->and($result->text)->not->toContain('Adequate:')
        ->and($result->text)->not->toContain('Insufficient:')
        ->and($result->text)->not->toContain('COVERAGE TOPICS');
});

test('the unknown-variant message still names the offending variant', function (): void {
    $composer = new OpeningTextComposer;

    expect(fn () => $composer->compose('reinterviews', 'X', 'en'))
        ->toThrow(InvalidArgumentException::class, 'OpeningTextComposer: unknown variant [reinterviews].');
});
