<?php

declare(strict_types=1);

namespace App\Support\Jwt;

use App\Models\Participant;
use App\Support\Participant\ExternalReference;
use Illuminate\Support\Facades\Cache;
use Tymon\JWTAuth\JWTAuth;

/**
 * CandidateTokenFactory (C6 — Participant + SSO Ingress).
 *
 * Responsible for minting both token types in the SSO flow:
 *   (a) sso-link JWT — RAW mint (custom claims only; NOT via fromUser)
 *   (b) candidate JWT — model-bound via JWTAuth::fromUser($participant)
 *
 * Also handles atomic single-use jti consumption via Redis SET NX.
 *
 * Security invariants:
 * - mintSsoLink(): RAW mint; sub=candidate_ref (satisfies required_claims); NO prv claim.
 *   jti is NOT stored in Redis at mint time. The EXCHANGE performs the sole consume.
 * - mintCandidateToken(): setTTL(120) REQUIRED (config default = 30 min → would expire
 *   mid-interview without this override).
 * - consumeJti(): atomic SET sso_jti:{jti} 1 NX EX {ttl} via Redis.
 *   Key prefix 'sso_jti:' is DISTINCT from tymon blacklist namespace.
 *   Returns false on NX-fail (key existed = replay attempt).
 *
 * REQ: M2M SSO-Link Mint + Public SSO Exchange + api-candidate Guard
 */
class CandidateTokenFactory
{
    /**
     * TTL, in minutes, of a raw sso-link mint (both the M2M mint and the
     * operator-facing mint, operator-interview-link design D1). The minter
     * derives `expires_at` from THIS constant so it can never drift from
     * `setTTL()` below — a single source of truth for the 30-minute
     * invariant this feature was designed around (see
     * `openspec/specs/participant-sso/spec.md` — "No Revocation Semantics").
     *
     * This is the lifetime of a link that is only RETURNED to a caller. A link
     * BEAI emails lives longer: `EntryLinkMinter` passes the configured
     * `candidate_invitations.emailed_link_ttl_minutes` as `$ttlMinutes`.
     */
    public const SSO_LINK_TTL_MINUTES = 30;

    /**
     * Mint a RAW sso-link JWT.
     *
     * The token carries custom claims only — NOT minted via JWTAuth::fromUser,
     * so NO prv claim is stamped. The 'sub' claim is set to candidate_ref to satisfy
     * tymon's required_claims validation (which includes 'sub').
     *
     * The jti is auto-populated by tymon's factory. It is NOT stored in Redis here —
     * the exchange endpoint performs the sole atomic consume on first use.
     *
     * The optional external reference (`external_id`, `source` — see
     * {@see ExternalReference}) is copied into the payload ONLY when present,
     * so a link minted without one carries exactly the claims it carried
     * before the reference existed (no null-valued keys). These claims are
     * readable by whoever holds the link (a JWT payload is base64, not
     * encrypted), exactly like the `email` and `display_name` claims beside
     * them: never put a secret in `source`.
     *
     * @param  array<string, mixed>  $claims  Must include 'candidate_ref', 'project_id', 'org_id', 'display_name',
     *                                        'email'. Optional: 'role_code', 'lang', 'external_id', 'source'.
     * @param  int  $ttlMinutes  Lifetime of this one token. Defaults to the 30-minute returned-link lifetime.
     * @return string Signed HS256 JWT
     */
    public static function mintSsoLink(array $claims, int $ttlMinutes = self::SSO_LINK_TTL_MINUTES): string
    {
        $candidateRef = $claims['candidate_ref'];

        $payload = [
            'sub' => $candidateRef, // satisfies tymon required_claims; raw candidate_ref
            'typ' => 'sso-link',
            'candidate_ref' => $candidateRef,
            'display_name' => $claims['display_name'],
            'email' => $claims['email'],
            'project_id' => $claims['project_id'],
            'org_id' => $claims['org_id'],
            'role_code' => $claims['role_code'] ?? null,
            'lang' => $claims['lang'] ?? null,
        ];

        foreach (['external_id', 'source'] as $optionalClaim) {
            if (isset($claims[$optionalClaim])) {
                $payload[$optionalClaim] = $claims[$optionalClaim];
            }
        }

        // RAW mint: iss/iat/exp/nbf/jti auto-populated by factory.
        // setTTL($ttlMinutes) for the sso-link token.
        // Build Payload then encode to token string.
        $jwt = app(JWTAuth::class);
        $factory = $jwt->factory();

        // The factory is a container singleton, so `setTTL()` outlives this
        // call. A 24-hour emailed-link lifetime left behind would become the
        // lifetime of every later token minted without its own `setTTL()` in a
        // long-lived worker (the user access token, for one). Put back whatever
        // was there.
        $previousTtl = $factory->getTTL();
        $factory->setTTL($ttlMinutes);

        try {
            // make(true), not make(): tymon's factory is a container singleton
            // whose claim collection ACCUMULATES across calls. Without the reset,
            // a link minted for candidate B in the same process would inherit the
            // optional `external_id`/`source` claims of candidate A minted just
            // before it (the scheduled-invitation sweep mints many links per run).
            $jwtPayload = $factory->customClaims($payload)->make(true);

            return $jwt->manager()->encode($jwtPayload)->get();
        } finally {
            $factory->setTTL($previousTtl);
        }
    }

    /**
     * Mint a candidate JWT, model-bound to the Participant.
     *
     * CRITICAL: setTTL(120) MUST be called before fromUser() to override the
     * global config/jwt.php default of 30 minutes. Without this, candidate tokens
     * expire mid-interview.
     *
     * tymon stamps prv = hash(App\Models\Participant) because lock_subject=true.
     * This prv claim is the SECONDARY guard-confusion defense:
     * a candidate JWT on the `api` (User) guard → prv mismatch → 401.
     *
     * @param  array<string, mixed>  $extra  Additional custom claims to embed.
     * @return string Signed HS256 JWT
     */
    public static function mintCandidateToken(Participant $participant, array $extra = []): string
    {
        $customClaims = array_merge([
            'typ' => 'candidate',
            'candidate_ref' => $participant->candidate_ref,
            'project_id' => $participant->project_id,
            'organization_id' => $participant->organization_id,
            'role_code' => $participant->role_code,
            'lang' => $participant->language,
        ], $extra);

        // setTTL(120) BEFORE fromUser — overrides the global 30-min default.
        $jwt = app(JWTAuth::class);
        $jwt->factory()->setTTL(120);

        // The factory is a container singleton that accumulates claims, and
        // decoding the sso-link in the same exchange request feeds ITS claims
        // (display_name, email, org_id and the optional external reference)
        // into that collection. Reset so the candidate token carries only the
        // claims built here; the external reference must never travel on it.
        $jwt->factory()->emptyClaims();

        return $jwt->customClaims($customClaims)->fromUser($participant);
    }

    /**
     * Atomic single-use jti consumption via Redis SET NX (Cache::add).
     *
     * Prefix 'sso_jti:' is DISTINCT from tymon's blacklist namespace to avoid collision.
     * TTL = max(token.exp - now, 60s) — floor prevents a just-expired token from
     * having a 0-second or negative TTL, keeping it in Redis briefly after natural expiry.
     *
     * Returns TRUE if successfully consumed (first use).
     * Returns FALSE if the key already existed (replay attempt → caller MUST return 401).
     */
    public static function consumeJti(string $jti, int $ttl): bool
    {
        $key = 'sso_jti:'.$jti;
        $safeTtl = max($ttl, 60);

        // Cache::add() is atomic SET NX in Redis-backed stores.
        // Returns true if added (key did not exist), false if it already existed.
        return Cache::add($key, 1, $safeTtl);
    }
}
