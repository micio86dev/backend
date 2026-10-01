<?php

declare(strict_types=1);

/**
 * VisitorIdentity (reusable-link-visitor-identity, design VD-3): the value
 * object that owns the normalisation of what a visitor typed.
 *
 * The middleware trims too, but the stored invariant must not depend on it: the
 * action is reachable from a concurrency actor and any future caller, and a
 * stored email must always be the lower-case form the duplicate check compares.
 *
 * REQ: Identity Input Is Trimmed And The Email Is Normalized
 *      (sdd/reusable-link-visitor-identity/spec/reusable-interview-links)
 */

use App\Actions\ReusableLinks\VisitorIdentity;

test('both values are trimmed', function (): void {
    $identity = VisitorIdentity::fromValidated("  Ada Lovelace \n", "\t ada@example.com  ");

    expect($identity->displayName)->toBe('Ada Lovelace')
        ->and($identity->email)->toBe('ada@example.com');
});

test('the email is lower-cased with the multibyte-safe function', function (): void {
    expect(VisitorIdentity::fromValidated('Ana', 'ÀNA@Example.IT')->email)->toBe('àna@example.it');
});

test('the name keeps its case, its inner spaces and every character it carries', function (): void {
    $name = "Zoe  O'Brien-Zizek <b>";

    expect(VisitorIdentity::fromValidated($name, 'zoe@example.test')->displayName)->toBe($name);
});

test('an uppercase name is not normalised', function (): void {
    expect(VisitorIdentity::fromValidated('ADA LOVELACE', 'ada@example.test')->displayName)->toBe('ADA LOVELACE');
});
