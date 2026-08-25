<?php

declare(strict_types=1);

/**
 * Every opening variant must INVITE AN ANSWER (interview-opening-no-dead-turn).
 *
 * The variants used to announce the topic and stop. The provider speaks the
 * line, the LLM has no user turn to respond to, and it waits — so the candidate
 * had to say "ok" before the first real question arrived. Observed at every
 * competency of a real interview on 2026-08-25.
 *
 * These assert the LANGUAGE FILES, not the composer: the composer only
 * interpolates, and the defect lived entirely in the copy.
 */

use Illuminate\Support\Facades\Lang;

dataset('locales', ['it', 'en']);

test('every opening variant ends in a question or an explicit invitation', function (string $locale): void {
    foreach (['first', 'next', 'resume', 'retry'] as $variant) {
        $text = trim((string) Lang::get("interview.opening.{$variant}", [], $locale));

        expect($text)->not->toBe(
            "interview.opening.{$variant}",
            "missing translation [{$locale}.{$variant}]"
        );

        // A question mark, or an explicit imperative for `resume`, which asks
        // the candidate to carry on rather than to start something new.
        $invites = str_ends_with($text, '?') || preg_match('/(avanti|carry on)/iu', $text) === 1;

        expect($invites)->toBeTrue(
            "[{$locale}.{$variant}] must invite an answer, otherwise the avatar "
            ."announces the topic and then waits for the candidate to prompt it: {$text}"
        );
    }
})->with('locales');

test('resume invites the candidate to CONTINUE, never to start a new episode', function (string $locale): void {
    // Someone resuming was already inside an episode. Asking for a new one
    // would discard what they had already told us.
    $text = (string) Lang::get('interview.opening.resume', [], $locale);

    expect($text)->not->toMatch('/(episodio specifico|specific episode)/iu');
})->with('locales');

test('the fresh-start variants DO ask for a specific episode', function (string $locale): void {
    foreach (['first', 'next', 'retry'] as $variant) {
        $text = (string) Lang::get("interview.opening.{$variant}", [], $locale);

        expect($text)->toMatch('/(episodio specifico|specific)/iu');
    }
})->with('locales');
