<?php

declare(strict_types=1);

/**
 * The gate-off fallback opening must INVITE AN ANSWER
 * (interview-opening-no-dead-turn).
 *
 * An opening that announces the topic and stops leaves the LLM with no user
 * turn to respond to, so it waits and the candidate has to say "ok" before
 * the first real question arrives. The fallback is the competency's only
 * question when it has no primaries, so it must be a question.
 *
 * These assert the LANGUAGE FILES, not the composer: the composer only
 * interpolates.
 */

use Illuminate\Support\Facades\Lang;

dataset('locales', ['it', 'en']);

test('the fallback opening ends in a question and asks for a specific episode', function (string $locale): void {
    $text = trim((string) Lang::get('interview.opening.fallback', [], $locale));

    expect($text)->not->toBe('interview.opening.fallback', "missing translation [{$locale}.fallback]")
        ->and($text)->toEndWith('?')
        ->and($text)->toMatch('/(episodio specifico|specific episode)/iu')
        ->and($text)->toContain(':competency');
})->with('locales');

test('only the fallback and the retry apology remain as opening templates', function (string $locale): void {
    $keys = array_keys((array) Lang::get('interview.opening', [], $locale));

    sort($keys);

    expect($keys)->toBe(['fallback', 'retry_authored']);
})->with('locales');

test('the retry apology ends on the question it wraps', function (string $locale): void {
    $text = (string) Lang::get('interview.opening.retry_authored', ['question' => 'Q?'], $locale);

    expect($text)->toEndWith('Q?');
})->with('locales');
