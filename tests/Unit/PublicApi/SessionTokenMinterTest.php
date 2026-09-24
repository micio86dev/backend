<?php

declare(strict_types=1);

/**
 * `App\Support\PublicApi\SessionTokenMinter` — public-api step 5, SPEC.md
 * §3.5, G-32.
 */

use App\Models\Organization;
use App\Models\Participant;
use App\Models\Project;
use App\Support\PublicApi\PublicId;
use App\Support\PublicApi\SessionTokenMinter;
use App\Support\Tenancy\TenantContextScope;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

function stmParticipant(): Participant
{
    $org = Organization::factory()->create();

    return TenantContextScope::runFor($org->id, function () use ($org): Participant {
        $project = Project::factory()->create(['organization_id' => $org->id]);

        // `mode` has a database DEFAULT (`live`) but `Model::save()` does
        // not re-fetch it — refresh() loads the value back so the
        // in-memory model matches what a real read (or a caller that
        // `forceFill()`s it explicitly, as `EnrolCandidate` does) sees.
        return Participant::factory()->forProject($project)->create()->refresh();
    });
}

test('a minted token parses back with the same subject, org and mode', function (): void {
    $participant = stmParticipant();
    $minter = new SessionTokenMinter;

    $minted = $minter->mint($participant);
    $verified = $minter->parse($minted->token);

    expect($verified)->not->toBeNull();
    expect($verified->subject)->toBe(PublicId::encode($participant));
    expect($verified->jti)->toBe($minted->jti);
    expect($verified->organizationPublicId)->toBe(PublicId::encode($participant->organization));
    expect($verified->mode)->toBe('live');
    expect($verified->isExpired())->toBeFalse();
});

test('a token signed with a different secret fails verification', function (): void {
    $participant = stmParticipant();

    $foreignConfig = Configuration::forSymmetricSigner(new Sha256, InMemory::plainText('a-completely-different-secret-value'));
    $now = new DateTimeImmutable;
    $foreignToken = $foreignConfig->builder()
        ->issuedBy('beai')
        ->permittedFor('embed')
        ->relatedTo(PublicId::encode($participant))
        ->identifiedBy('foreign-jti')
        ->issuedAt($now)
        ->expiresAt($now->modify('+15 minutes'))
        ->getToken($foreignConfig->signer(), $foreignConfig->signingKey())
        ->toString();

    expect((new SessionTokenMinter)->parse($foreignToken))->toBeNull();
});

test('a token with the wrong audience fails verification', function (): void {
    $config = Configuration::forSymmetricSigner(new Sha256, InMemory::plainText(config()->string('public_api.session_secret')));
    $now = new DateTimeImmutable;
    $wrongAudience = $config->builder()
        ->issuedBy('beai')
        ->permittedFor('some-other-audience')
        ->relatedTo('int_01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->identifiedBy('a-jti')
        ->issuedAt($now)
        ->expiresAt($now->modify('+15 minutes'))
        ->getToken($config->signer(), $config->signingKey())
        ->toString();

    expect((new SessionTokenMinter)->parse($wrongAudience))->toBeNull();
});

test('an expired token still parses (signature/aud valid) but isExpired() is true', function (): void {
    $config = Configuration::forSymmetricSigner(new Sha256, InMemory::plainText(config()->string('public_api.session_secret')));
    $now = new DateTimeImmutable('-1 hour');
    $expired = $config->builder()
        ->issuedBy('beai')
        ->permittedFor('embed')
        ->relatedTo('int_01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->identifiedBy('a-jti')
        ->issuedAt($now)
        ->expiresAt($now->modify('+15 minutes'))
        ->withClaim('org', 'org_01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->withClaim('mode', 'live')
        ->getToken($config->signer(), $config->signingKey())
        ->toString();

    $verified = (new SessionTokenMinter)->parse($expired);

    expect($verified)->not->toBeNull();
    expect($verified->isExpired())->toBeTrue();
});

test('a garbage string never parses', function (): void {
    expect((new SessionTokenMinter)->parse('not-a-jwt-at-all'))->toBeNull();
});

test('an empty string never parses', function (): void {
    expect((new SessionTokenMinter)->parse(''))->toBeNull();
});

test('a token with the wrong issuer fails verification', function (): void {
    $config = Configuration::forSymmetricSigner(new Sha256, InMemory::plainText(config()->string('public_api.session_secret')));
    $now = new DateTimeImmutable;
    $wrongIssuer = $config->builder()
        ->issuedBy('not-beai')
        ->permittedFor('embed')
        ->relatedTo('int_01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->identifiedBy('a-jti')
        ->issuedAt($now)
        ->expiresAt($now->modify('+15 minutes'))
        ->withClaim('org', 'org_01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->withClaim('mode', 'live')
        ->getToken($config->signer(), $config->signingKey())
        ->toString();

    expect((new SessionTokenMinter)->parse($wrongIssuer))->toBeNull();
});

test('a token missing a required claim (org) fails verification', function (): void {
    $config = Configuration::forSymmetricSigner(new Sha256, InMemory::plainText(config()->string('public_api.session_secret')));
    $now = new DateTimeImmutable;
    $missingOrg = $config->builder()
        ->issuedBy('beai')
        ->permittedFor('embed')
        ->relatedTo('int_01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->identifiedBy('a-jti')
        ->issuedAt($now)
        ->expiresAt($now->modify('+15 minutes'))
        ->withClaim('mode', 'live')
        ->getToken($config->signer(), $config->signingKey())
        ->toString();

    expect((new SessionTokenMinter)->parse($missingOrg))->toBeNull();
});

test('mint() throws when public_api.session_secret is unset', function (): void {
    config(['public_api.session_secret' => null]);
    $participant = stmParticipant();

    expect(fn () => (new SessionTokenMinter)->mint($participant))->toThrow(RuntimeException::class);
});

test('parse() throws when public_api.session_secret is unset', function (): void {
    config(['public_api.session_secret' => null]);

    expect(fn () => (new SessionTokenMinter)->parse('any-non-empty-string'))->toThrow(RuntimeException::class);
});
