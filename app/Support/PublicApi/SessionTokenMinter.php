<?php

declare(strict_types=1);

namespace App\Support\PublicApi;

use App\Models\Participant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Mints and verifies the BEAI Public API (`/v1`) session token — the
 * browser-facing credential SPEC.md §3.5 describes, public-api step 5, G-32.
 *
 * A DELIBERATELY separate credential from the candidate JWT
 * `App\Support\Jwt\CandidateTokenFactory` mints: it is signed with its OWN
 * dedicated secret (`config('public_api.session_secret')`, never
 * `config('jwt.secret')`), grants nothing beyond "load the embed/hosted
 * page for this interview", and cannot authenticate anywhere on `/v1`
 * (`T-TOK-007` asserts this — a session token presented as a Bearer key
 * simply never resolves an `ApiClient`).
 *
 * Library choice (task instruction — check `composer show` before adding a
 * dependency): neither `firebase/php-jwt` NOR a project-level dependency on
 * `lcobucci/jwt` needed adding. `firebase/php-jwt` is not in `composer.lock`
 * at all. `lcobucci/jwt` (5.6.0) IS already in `vendor/` — a transitive
 * dependency of `tymon/jwt-auth` — so it is used here directly rather than
 * reusing `Tymon\JWTAuth\JWTAuth`: that facade is wired to exactly ONE
 * configured secret (`config('jwt.secret')`) for the whole application, and
 * SPEC.md §3.5 requires a genuinely SEPARATE secret for this token type —
 * reconfiguring the shared JWTAuth manager per call would be far more
 * invasive than using the library tymon itself depends on directly for
 * this one, narrow, dedicated purpose. No new Composer dependency was added.
 *
 * Signature verification uses `Lcobucci\JWT\Validation\Constraint\SignedWith`
 * only — never `StrictValidAt` (which needs a `Psr\Clock\ClockInterface`
 * this codebase has no existing implementation of, and pulling one in for a
 * single check would be its own small dependency decision). `exp`/`aud` are
 * instead read straight off the parsed claims and compared here, which also
 * gives the caller (the embed exchange controller) the EXACT distinction
 * SPEC.md §3.5 requires between an expired/mis-signed/wrong-audience token
 * (`401 token_invalid`) and a consumed/revoked/non-pending one
 * (`410 token_consumed`) — a distinction a single boolean `isValid()` could
 * not carry back to the caller.
 */
final class SessionTokenMinter
{
    private const ISSUER = 'beai';

    private const AUDIENCE = 'embed';

    /**
     * @throws RuntimeException when `public_api.session_secret` is unset —
     *                          fails loud rather than signing with an empty key (see `config/public_api.php`'s
     *                          own docblock: required in every non-testing environment).
     */
    public function mint(Participant $participant): MintedSessionToken
    {
        $config = $this->configuration();
        $now = CarbonImmutable::now();
        $ttlMinutes = config()->integer('public_api.session_token_ttl_minutes', 15);
        $expiresAt = $now->addMinutes($ttlMinutes);
        $jti = (string) Str::ulid();

        $organization = $participant->organization;

        if ($organization === null) {
            throw new LogicException(sprintf(
                'Participant %d: organization_id %d does not resolve to an Organization row.',
                $participant->id,
                $participant->organization_id,
            ));
        }

        $token = $config->builder()
            ->issuedBy(self::ISSUER)
            ->permittedFor(self::AUDIENCE)
            ->relatedTo(self::nonEmpty(PublicId::encode($participant)))
            ->identifiedBy($jti)
            ->issuedAt($now)
            ->expiresAt($expiresAt)
            ->withClaim('org', PublicId::encode($organization))
            ->withClaim('mode', $participant->mode->value)
            ->getToken($config->signer(), $config->signingKey());

        return new MintedSessionToken($token->toString(), $jti, $expiresAt);
    }

    /**
     * Parses and cryptographically verifies `$raw`. Returns the decoded
     * claims on success; `null` on ANY failure — malformed structure,
     * unsupported header, wrong signature, wrong issuer, or wrong audience.
     * Deliberately does NOT check `exp` here — see `VerifiedSessionToken::isExpired()`,
     * which the caller consults separately so it can tell an
     * expired-but-otherwise-valid token apart from a genuinely malformed one
     * (both answer `401 token_invalid` today, per G-32, but the distinction
     * is real and a future caller may need it).
     */
    public function parse(string $raw): ?VerifiedSessionToken
    {
        if ($raw === '') {
            return null;
        }

        $config = $this->configuration();

        try {
            $token = $config->parser()->parse($raw);
        } catch (Throwable) {
            // Lcobucci\JWT\Parser::parse() documents CannotDecodeContent,
            // InvalidTokenStructure and UnsupportedHeaderFound — caught
            // broadly here (PHPStan flags the specific subtypes as
            // unreachable against the concrete Token\Parser implementation
            // it analyses, but the interface's own docblock is the honest
            // contract a DIFFERENT Parser implementation could still throw
            // from) so ANY parse failure answers "not a valid token",
            // matching this method's own docblock.
            return null;
        }

        if (! $token instanceof Plain) {
            return null;
        }

        if (! $config->validator()->validate($token, new SignedWith($config->signer(), $config->signingKey()))) {
            return null;
        }

        $claims = $token->claims();

        if ($claims->get('iss') !== self::ISSUER) {
            return null;
        }

        $audience = $claims->get('aud');
        if (! is_array($audience) || ! in_array(self::AUDIENCE, $audience, true)) {
            return null;
        }

        $sub = $claims->get('sub');
        $jti = $claims->get('jti');
        $org = $claims->get('org');
        $mode = $claims->get('mode');
        $exp = $token->claims()->get('exp');

        if (! is_string($sub) || ! is_string($jti) || ! is_string($org) || ! is_string($mode) || ! $exp instanceof \DateTimeImmutable) {
            return null;
        }

        return new VerifiedSessionToken($sub, $jti, $org, $mode, CarbonImmutable::instance($exp));
    }

    /**
     * `App\Support\PublicApi\PublicId::encode()` never actually returns an
     * empty string (a prefix plus a 26-char ULID), but its static return
     * type is plain `string` — `Lcobucci\JWT\Builder::relatedTo()` requires
     * `non-empty-string`. A real, honest runtime check narrows the type
     * rather than an `@var` override.
     *
     * @return non-empty-string
     */
    private static function nonEmpty(string $value): string
    {
        if ($value === '') {
            throw new LogicException('PublicId::encode() returned an empty string.');
        }

        return $value;
    }

    private function configuration(): Configuration
    {
        $secret = config('public_api.session_secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException(
                'public_api.session_secret is not configured — set PUBLIC_API_SESSION_SECRET.'
            );
        }

        return Configuration::forSymmetricSigner(new Sha256, InMemory::plainText($secret));
    }
}
