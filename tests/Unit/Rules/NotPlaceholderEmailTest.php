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
use App\Support\Participant\PlaceholderEmail;
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

// ─── The request's OWN placeholder (re-issue paths) ──────────────────────────

/**
 * Validate `$email` beside a `candidate_ref`, with the rule told where the
 * reference is (`$referenceKey`), or strict when it is null.
 */
function notPlaceholderEmailFailsBeside(string $email, string $candidateRef, ?string $referenceKey): bool
{
    return Validator::make(
        ['candidate_ref' => $candidateRef, 'email' => $email],
        ['email' => [new NotPlaceholderEmail($referenceKey)]],
    )->fails();
}

test('with a reference key, the reference\'s own legacy and purged placeholders pass', function (): void {
    expect(notPlaceholderEmailFailsBeside(PlaceholderEmail::for('ref-1'), 'ref-1', 'candidate_ref'))->toBeFalse()
        ->and(notPlaceholderEmailFailsBeside(PlaceholderEmail::forPurged('ref-1'), 'ref-1', 'candidate_ref'))->toBeFalse();
});

test('with a reference key, another reference\'s placeholder and any other reserved address still fail, in any case', function (string $address): void {
    expect(notPlaceholderEmailFailsBeside($address, 'ref-1', 'candidate_ref'))->toBeTrue();
})->with([
    'another reference legacy' => 'ref-2@invalid.beai.local',
    'another reference purged' => 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc@purged.beai.invalid',
    'any address under the legacy domain' => 'x@invalid.beai.local',
    'any address under the purged domain' => 'x@purged.beai.invalid',
    'the own placeholder in another case' => 'REF-1@INVALID.BEAI.LOCAL',
    'the own placeholder padded' => ' ref-1@invalid.beai.local ',
]);

test('without a reference key the rule is strict: not even the own placeholder passes', function (): void {
    expect(notPlaceholderEmailFailsBeside(PlaceholderEmail::for('ref-1'), 'ref-1', null))->toBeTrue()
        ->and(notPlaceholderEmailFailsBeside(PlaceholderEmail::forPurged('ref-1'), 'ref-1', null))->toBeTrue();
});

test('the reference is read from the validated data, and a missing or non-string one grants nothing', function (): void {
    $own = PlaceholderEmail::for('ref-1');

    expect(Validator::make(['email' => $own], ['email' => [new NotPlaceholderEmail('candidate_ref')]])->fails())->toBeTrue()
        ->and(Validator::make(['candidate_ref' => ['ref-1'], 'email' => $own], ['email' => [new NotPlaceholderEmail('candidate_ref')]])->fails())->toBeTrue()
        ->and(Validator::make(['candidate_ref' => 123, 'email' => $own], ['email' => [new NotPlaceholderEmail('candidate_ref')]])->fails())->toBeTrue();
});

test('a nested reference key is read with dot notation', function (): void {
    $own = PlaceholderEmail::for('ref-1');

    expect(Validator::make(
        ['candidate' => ['candidate_ref' => 'ref-1', 'email' => $own]],
        ['candidate.email' => [new NotPlaceholderEmail('candidate.candidate_ref')]],
    )->fails())->toBeFalse();
});
