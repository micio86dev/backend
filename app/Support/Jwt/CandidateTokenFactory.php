<?php

declare(strict_types=1);

namespace App\Support\Jwt;

use App\Models\Participant;
use Illuminate\Support\Facades\Cache;
use Tymon\JWTAuth\Facades\JWTAuth;

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
     * Mint a RAW sso-link JWT.
     *
     * The token carries custom claims only — NOT minted via JWTAuth::fromUser,
     * so NO prv claim is stamped. The 'sub' claim is set to candidate_ref to satisfy
     * tymon's required_claims validation (which includes 'sub').
     *
     * The jti is auto-populated by tymon's factory. It is NOT stored in Redis here —
     * the exchange endpoint performs the sole atomic consume on first use.
     *
     * @param  array<string, mixed>  $claims  Must include 'candidate_ref', 'project_id', 'org_id', 'display_name'.
     *                                        Optional: 'role_code', 'lang'.
     * @return string Signed HS256 JWT
     */
    public static function mintSsoLink(array $claims): string
    {
        $candidateRef = $claims['candidate_ref'];

        $payload = [
            'sub' => $candidateRef, // satisfies tymon required_claims; raw candidate_ref
            'typ' => 'sso-link',
            'candidate_ref' => $candidateRef,
            'display_name' => $claims['display_name'],
            'project_id' => $claims['project_id'],
            'org_id' => $claims['org_id'],
            'role_code' => $claims['role_code'] ?? null,
            'lang' => $claims['lang'] ?? null,
        ];

        // RAW mint: iss/iat/exp/nbf/jti auto-populated by factory.
        // setTTL(30) = 30 minutes for sso-link token.
        // Build Payload then encode to token string.
        JWTAuth::factory()->setTTL(30);
        $jwtPayload = JWTAuth::factory()->customClaims($payload)->make();

        return JWTAuth::encode($jwtPayload)->get();
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
        JWTAuth::factory()->setTTL(120);

        return JWTAuth::customClaims($customClaims)->fromUser($participant);
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
