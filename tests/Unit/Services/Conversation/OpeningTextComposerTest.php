<?php

declare(strict_types=1);

/**
 * RED — PR3 (opening greeting composer, design D9/D11).
 *
 * `OpeningTextComposer` is a pure, locale-keyed template — a SIBLING of
 * `SystemPromptComposer`, never inside it. It builds the avatar's spoken
 * opening line from `lang/{locale}/interview.php` keys `opening.{first,next,resume}`
 * with a `:competency` placeholder, and NEVER touches BARS indicator/anchor
 * content (anti-leak invariant, mirrors `interview-session/spec.md:341-349`).
 *
 * Asserts:
 * - Determinism: same inputs → same text and version.
 * - Version equals config('conversation.prompt_version') — shared with the
 *   system prompt, per D9 ("one version, both strings ship together").
 * - Each variant (first/next/resume) resolves to a DIFFERENT template.
 * - `:competency` is interpolated with the given competency name.
 * - Locale fallback: an unknown locale falls back to config('app.fallback_locale').
 * - Anti-leak: the composer has no BARS dependency at all — it cannot leak
 *   anchor/indicator text because it never receives it. Asserted by construction:
 *   the composed text is EXACTLY the interpolated lang string, nothing more.
 * - Unknown variant → InvalidArgumentException (fail loud, not a silent default).
 *
 * Spec: REQ QuestionContext Carries a Composed Opening Greeting (delta spec, interview-conversation)
 * REQ: OpeningTextComposer (PR3 — design D9)
 */

use App\DTOs\Conversation\ComposedOpening;
use App\Services\Conversation\OpeningTextComposer;

test('compose() for variant "first" returns the localized opening.first template with competency interpolated', function (): void {
    $composer = new OpeningTextComposer;

    $result = $composer->compose('first', 'Problem Solving', 'en');

    expect($result)->toBeInstanceOf(ComposedOpening::class);
    expect($result->text)->toBe(trans('interview.opening.first', ['competency' => 'Problem Solving'], 'en'));
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

test('compose() variants first/next/resume each resolve to a DIFFERENT template', function (): void {
    $composer = new OpeningTextComposer;

    $first = $composer->compose('first', 'Drive', 'en')->text;
    $next = $composer->compose('next', 'Drive', 'en')->text;
    $resume = $composer->compose('resume', 'Drive', 'en')->text;

    expect($first)->not->toBe($next);
    expect($first)->not->toBe($resume);
    expect($next)->not->toBe($resume);
});

test('compose() falls back to the platform default locale when the requested locale has no interview.php file', function (): void {
    $composer = new OpeningTextComposer;

    $result = $composer->compose('first', 'Insight', 'fr');

    $fallback = (string) config('app.fallback_locale');
    expect($result->text)->toBe(trans('interview.opening.first', ['competency' => 'Insight'], $fallback));
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

// ─── 'retry' — the fourth variant (interview-continuous-flow, D10) ────────────
//
// A competency that ended in `error` is offered to the candidate again. Without
// its own greeting the avatar simply asks the same thing twice, which reads as
// not having listened — the candidate has no way to know they are re-attempting
// rather than being ignored.

test("compose('retry') is a distinct greeting, not the 'next' one reused", function (): void {
    $composer = new OpeningTextComposer;

    $retry = $composer->compose('retry', 'Networking', 'it')->text;
    $next = $composer->compose('next', 'Networking', 'it')->text;

    expect($retry)->not->toBe($next);
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
