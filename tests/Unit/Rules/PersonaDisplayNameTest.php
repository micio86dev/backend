<?php

declare(strict_types=1);

/**
 * `App\Rules\PersonaDisplayName` (live-interview-call-chrome, T-A3.2, design D7): the ONE rule
 * for the interviewer's display name, shared by create, update, import and the read-side
 * accessor, so a file can never install a name the form would refuse.
 *
 * The name is shown to the candidate in a chip and a caption prefix, so it is plain text of 1 to
 * 60 characters, and it never names the avatar provider or its infrastructure (provider opacity).
 * A refusal never repeats the matched vendor word: a message that echoes it back is itself a leak
 * into logs and screenshots.
 *
 * REQ: avatar-templates "A template MAY carry an interviewer display name"
 */

use App\Rules\PersonaDisplayName;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

function personaNameFails(mixed $value): bool
{
    return Validator::make(['name' => $value], ['name' => [new PersonaDisplayName]])->fails();
}

test('1 to 60 characters after trimming are accepted and 61 are refused', function (): void {
    expect(PersonaDisplayName::problem(str_repeat('a', 60)))->toBeNull()
        ->and(PersonaDisplayName::problem('  '.str_repeat('a', 60).'  '))->toBeNull()
        ->and(PersonaDisplayName::problem('A'))->toBeNull()
        ->and(PersonaDisplayName::problem(str_repeat('a', 61)))->not->toBeNull()
        ->and(PersonaDisplayName::problem(str_repeat('è', 61)))->not->toBeNull()
        ->and(PersonaDisplayName::problem(str_repeat('è', 60)))->toBeNull()
        ->and(PersonaDisplayName::problem('   '))->not->toBeNull();
});

test('markup, line breaks and format characters are refused', function (string $value): void {
    expect(PersonaDisplayName::problem($value))->not->toBeNull()
        ->and(personaNameFails($value))->toBeTrue();
})->with([
    'opening bracket' => '<b>Giulia</b>',
    'closing bracket only' => 'Giulia>',
    'line feed' => "Giu\nlia",
    'tab' => "Giu\tlia",
    'null byte' => "Giu\0lia",
    'zero width space' => "Giu\u{200B}lia",
    'malformed UTF-8 hiding markup' => "\xFF<b>",
    'trailing null byte' => "Giulia\0",
    'trailing line feed' => "Giulia\n",
    'leading vertical tab' => "\x0BGiulia",
    'unicode line separator' => "Giu\u{2028}lia",
]);

test('a provider or infrastructure name is refused in any case and spacing', function (string $value): void {
    expect(PersonaDisplayName::problem($value))->not->toBeNull();
})->with([
    'HeyGen' => 'HeyGen',
    'spaced HeyGen' => 'Hey Gen',
    'lower case' => 'heygen',
    'LiveAvatar' => 'LiveAvatar',
    'spaced LiveAvatar' => 'live avatar',
    'Tavus in a phrase' => 'Tavus Coach',
    'daily' => 'daily',
    'daily.co' => 'daily.co',
    'LiveKit in a phrase' => 'My LiveKit agent',
    'spaced LiveKit' => 'live kit',
    'upper case' => 'TAVUS',
    'malformed UTF-8 after the name' => "Tavus\xC3",
    'underscore suffix' => 'Tavus_bot',
]);

test('a name that merely resembles a vendor word is accepted', function (string $value): void {
    expect(PersonaDisplayName::problem($value))->toBeNull();
})->with(['Giulia', 'Dailyn', 'Taverna', 'Heygenie', 'Live Aviator', 'Marco Rossi']);

test('a value that is not text is refused', function (mixed $value): void {
    expect(PersonaDisplayName::problem($value))->not->toBeNull()
        ->and(personaNameFails($value))->toBeTrue();
})->with([[123], [['Giulia']], [true]]);

test('the refusal message never repeats the matched vendor word', function (string $value, string $word): void {
    $message = (string) PersonaDisplayName::problem($value);

    expect($message)->not->toBe('')
        ->and(mb_strtolower($message))->not->toContain($word);
    expect(Validator::make(['name' => $value], ['name' => [new PersonaDisplayName]])->errors()->first('name'))
        ->not->toContain($word);
})->with([
    ['Tavus Coach', 'tavus'],
    ['heygen', 'heygen'],
    ['My LiveKit agent', 'livekit'],
    ['live avatar', 'avatar'],
]);

test('the rule passes a valid name through the validator', function (): void {
    expect(personaNameFails('Giulia'))->toBeFalse();
});
