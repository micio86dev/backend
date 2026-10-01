<?php

declare(strict_types=1);

/**
 * `App\Rules\NotPlaceholderEmail` (reusable-link-visitor-identity, design VD-4):
 * an address under the reserved placeholder domain is not an address a person
 * can give.
 *
 * `@invalid.beai.local` passes Laravel's RFC `email` rule. A visitor typing it
 * would create a row that `PlaceholderEmail::is()` classifies as synthesised, so
 * a participant carrying a placeholder would no longer mean "never had an
 * address", and the invitation job's refusal log would be wrong about the row.
 *
 * The rule runs through the real `Validator` (it translates its message, which
 * needs the booted application), and its failure reads exactly like the
 * framework's own invalid-email failure, so it discloses nothing.
 *
 * REQ: Identity Fields Are Validated First And Never Disclose The Token
 *      (sdd/reusable-link-visitor-identity/spec/reusable-interview-links)
 */

use App\Rules\NotPlaceholderEmail;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Validate one value against the rule alone.
 */
function notPlaceholderEmailFails(mixed $value): bool
{
    return Validator::make(['email' => $value], ['email' => [new NotPlaceholderEmail]])->fails();
}

test('an address under the reserved domain is refused whatever its case or padding', function (string $address): void {
    expect(notPlaceholderEmailFails($address))->toBeTrue();
})->with([
    'the exact placeholder domain' => 'x@invalid.beai.local',
    'upper case' => 'X@INVALID.BEAI.LOCAL',
    'mixed case' => 'x@Invalid.Beai.Local',
    'surrounding whitespace' => '  x@invalid.beai.local  ',
    'a candidate reference as the local part' => 'rlv_01JABCDEFGHJKMNPQRSTVWXYZ0@invalid.beai.local',
]);

test('an ordinary address passes, and so does a domain that only contains the reserved one', function (string $address): void {
    expect(notPlaceholderEmailFails($address))->toBeFalse();
})->with([
    'an ordinary address' => 'ada@example.test',
    // `str_ends_with` is the decided behaviour: a longer domain is NOT under the
    // reserved one, it is somebody else's.
    'the reserved domain as a subdomain label' => 'x@invalid.beai.local.example.com',
    'a lookalike' => 'x@xinvalid.beai.local',
]);

test('the failure reads exactly like the framework invalid-email failure and names nothing', function (): void {
    $framework = Validator::make(['email' => 'not-an-email'], ['email' => ['email']])->errors()->first('email');
    $ours = Validator::make(['email' => 'x@invalid.beai.local'], ['email' => [new NotPlaceholderEmail]])->errors()->first('email');

    expect($ours)->toBe($framework)
        ->and($ours)->not->toContain('invalid.beai.local')
        ->and($ours)->not->toContain('placeholder');
});

test('a value that is not a string is left to the other rules and never throws', function (mixed $value): void {
    expect(notPlaceholderEmailFails($value))->toBeFalse();
})->with([
    'an array' => [['x@invalid.beai.local']],
    'an integer' => [123],
    'a boolean' => [true],
]);
