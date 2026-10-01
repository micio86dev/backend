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
 * Pure logic, no database. The predicate started as exactly the check the
 * invitation job used before this class existed, and was then widened to ignore
 * case and surrounding whitespace (reusable-link-visitor-identity): it now also
 * guards validation, where refusing more is the safe direction.
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

test('is() is case- and whitespace-insensitive', function (): void {
    // The predicate used to be exactly the invitation job's check, a case
    // sensitive, untrimmed `str_ends_with()`. It now also guards validation (a
    // visitor must not be able to type a placeholder) and, later, personal-data
    // deletion, so a placeholder spelled in another case or with padding must
    // still be recognised: refusing more is the safe direction for the mail
    // guard, and the reserved domain resolves nowhere in any spelling.
    expect(PlaceholderEmail::is('x@INVALID.BEAI.LOCAL'))->toBeTrue()
        ->and(PlaceholderEmail::is('x@invalid.beai.local '))->toBeTrue()
        ->and(PlaceholderEmail::is(" x@invalid.beai.local\n"))->toBeTrue()
        ->and(PlaceholderEmail::is('X@Invalid.Beai.Local'))->toBeTrue();
});

// ─── The purged placeholder (reusable-link-visitor-identity) ─────────────────

test('the purged placeholder is the SHA-256 of the candidate reference at the purged domain, 84 characters for any reference', function (): void {
    expect(PlaceholderEmail::PURGED_DOMAIN)->toBe('@purged.beai.invalid')
        ->and(PlaceholderEmail::forPurged('ref-1'))->toBe(hash('sha256', 'ref-1').'@purged.beai.invalid')
        ->and(strlen(PlaceholderEmail::forPurged('ref-1')))->toBe(84)
        ->and(strlen(PlaceholderEmail::forPurged(str_repeat('r', 255))))->toBe(84)
        ->and(PlaceholderEmail::forPurged('ref-1'))->not->toBe(PlaceholderEmail::forPurged('ref-2'));
});

test('is() recognises BOTH reserved domains in any case, with or without padding', function (string $address): void {
    expect(PlaceholderEmail::is($address))->toBeTrue();
})->with([
    'the legacy domain' => 'x@invalid.beai.local',
    'the purged domain' => 'x@purged.beai.invalid',
    'the purged domain in capitals' => 'X@PURGED.BEAI.INVALID',
    'the purged domain padded' => "  x@purged.beai.invalid\n",
    'a purged placeholder' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa@purged.beai.invalid',
]);

test('is() is still false for a real address and for a domain that merely contains the purged one', function (string $address): void {
    expect(PlaceholderEmail::is($address))->toBeFalse();
})->with([
    'an ordinary address' => 'giulia@example.test',
    'the purged domain as a subdomain label' => 'x@purged.beai.invalid.example.test',
    'a lookalike' => 'x@xpurged.beai.invalid',
]);

test('isOwn() is true only for the reference\'s own legacy or purged placeholder', function (): void {
    $ref = 'ref-1';

    expect(PlaceholderEmail::isOwn(PlaceholderEmail::for($ref), $ref))->toBeTrue()
        ->and(PlaceholderEmail::isOwn(PlaceholderEmail::forPurged($ref), $ref))->toBeTrue()
        ->and(PlaceholderEmail::isOwn(PlaceholderEmail::for('ref-2'), $ref))->toBeFalse()
        ->and(PlaceholderEmail::isOwn(PlaceholderEmail::forPurged('ref-2'), $ref))->toBeFalse()
        ->and(PlaceholderEmail::isOwn('x@invalid.beai.local', $ref))->toBeFalse()
        ->and(PlaceholderEmail::isOwn('x@purged.beai.invalid', $ref))->toBeFalse()
        ->and(PlaceholderEmail::isOwn('ada@example.com', $ref))->toBeFalse();
});

test('the SQL twin of the purged placeholder hashes the named column and appends the same domain', function (): void {
    expect(PlaceholderEmail::purgedSqlExpression())
        ->toBe("encode(sha256(convert_to(candidate_ref, 'UTF8')), 'hex') || '@purged.beai.invalid'")
        ->and(PlaceholderEmail::purgedSqlExpression('p.candidate_ref'))
        ->toBe("encode(sha256(convert_to(p.candidate_ref, 'UTF8')), 'hex') || '@purged.beai.invalid'");
});
