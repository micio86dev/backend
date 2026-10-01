<?php

declare(strict_types=1);

/**
 * RED — reusable-interview-links B3a.1: the `PlaceholderEmail` convention.
 *
 * `participants.email` is NOT NULL (CLAUDE.md ruling 8), so a participant who
 * has no address of a person (a backfilled legacy row, an sso-link minted
 * before the column existed, and now an anonymous reusable-link visitor)
 * carries `<candidate_ref>@invalid.beai.local`. This class is the one owner of
 * that spelling and of the predicate that recognises it, so the writer (the
 * exchange fallback, the redemption action) and the reader (the invitation
 * job's refusal) can never drift apart.
 *
 * Pure logic, no database. The matching rules are the ones the invitation job
 * used before this class existed (`str_ends_with` on the lowercase suffix: case
 * sensitive, no trimming) and are pinned as they were, so extracting the
 * predicate changes no behaviour.
 */

use App\Support\Participant\PlaceholderEmail;

test('the placeholder address of a candidate reference is that reference at the reserved domain', function (): void {
    expect(PlaceholderEmail::for('rlv_01JABCDEFGHJKMNPQRSTVWXYZ0'))
        ->toBe('rlv_01JABCDEFGHJKMNPQRSTVWXYZ0@invalid.beai.local')
        ->and(PlaceholderEmail::for('cand-1'))->toBe('cand-1@invalid.beai.local');
});

test('the domain constant is the reserved .local suffix including the at sign', function (): void {
    expect(PlaceholderEmail::DOMAIN)->toBe('@invalid.beai.local');
});

test('an address built by for() is recognised by is()', function (): void {
    expect(PlaceholderEmail::is(PlaceholderEmail::for('cand-1')))->toBeTrue();
});

test('is() recognises the suffix on any local part', function (string $address): void {
    expect(PlaceholderEmail::is($address))->toBeTrue();
})->with([
    'a visitor' => 'rlv_01JABCDEFGHJKMNPQRSTVWXYZ0@invalid.beai.local',
    'a backfilled legacy row' => 'beai-demo-c-001@invalid.beai.local',
    'an empty local part' => '@invalid.beai.local',
]);

test('is() is false for a real address', function (string $address): void {
    expect(PlaceholderEmail::is($address))->toBeFalse();
})->with([
    'an ordinary address' => 'giulia@example.test',
    'the domain as a subdomain label' => 'giulia@invalid.beai.local.example.test',
    'a lookalike domain' => 'giulia@xinvalid.beai.local',
    'the suffix in the local part' => 'x@invalid.beai.local@example.test',
    'an empty string' => '',
]);

test('is() keeps the case and whitespace semantics the invitation job had', function (): void {
    // `str_ends_with()` is case sensitive and does not trim. Pinned rather
    // than "improved": a looser predicate would be a behaviour change in a
    // refactor commit, and a placeholder that resolves nowhere is harmless to
    // mail either way (`.local` is reserved by RFC 6762).
    expect(PlaceholderEmail::is('x@INVALID.BEAI.LOCAL'))->toBeFalse()
        ->and(PlaceholderEmail::is('x@invalid.beai.local '))->toBeFalse()
        ->and(PlaceholderEmail::is(" x@invalid.beai.local\n"))->toBeFalse();
});
