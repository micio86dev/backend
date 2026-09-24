<?php

declare(strict_types=1);

namespace App\Http\Controllers\Embed;

use App\Http\Controllers\Controller;
use App\Models\InterviewEvent;
use App\Models\Organization;
use App\Models\Participant;
use App\Support\Jwt\CandidateTokenFactory;
use App\Support\PublicApi\Problem;
use App\Support\PublicApi\PublicId;
use App\Support\PublicApi\SessionTokenMinter;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/embed/exchange?token=<session_token>` — BEAI Public API session-
 * token exchange (public-api step 5, SPEC.md §3.5, G-32).
 *
 * PUBLIC endpoint, outside `/v1` — no API key, no `TenantContext`. G-32's
 * choice: reuses the EXISTING candidate JWT (`App\Support\Jwt\
 * CandidateTokenFactory::mintCandidateToken()`, the SAME credential the SSO
 * link flow already mints and `useCandidateSession` already stores) rather
 * than the `httpOnly` cookie SPEC.md §3.5 originally described — the
 * candidate app already runs entirely on that bearer JWT, and a cookie
 * would be a SECOND credential path inheriting G-11's third-party-cookie
 * failure mode for no benefit. The cookie-flag test becomes "no cookie is
 * set" (`T-TOK-008`).
 *
 * Not part of the `/v1` `ErrorCode` enum (reported as a G-item — this
 * endpoint's error codes, `token_invalid`/`token_consumed`, are outside the
 * `/v1` contract surface entirely, since this route is not under `/v1`):
 * `401 token_invalid` — malformed, mis-signed, wrong-audience, or expired
 * token; an `org` claim that does not decode to a real organization; a `sub`
 * that resolves to no participant WITHIN that organization (gga finding 1 —
 * both the participant lookup and the consume UPDATE below are scoped to the
 * token's own `org` claim, never an unscoped `public_id` match, even though
 * `public_id` is globally unique). `410 token_consumed` — a `jti` that does
 * not match the participant's CURRENT `session_token_jti` (already consumed,
 * or superseded by a later mint), or an interview no longer `pending` at the
 * moment the consume UPDATE actually runs (folded into that statement's own
 * `WHERE`, not a separate earlier read — see `exchange()`'s own comment).
 *
 * Consuming a token does NOT change `participants.status` (G-11/§3.5):
 * `in_progress` begins only when the first interview session actually
 * starts, a decision this controller has no part in.
 */
final class ExchangeController extends Controller
{
    public function __construct(
        private readonly SessionTokenMinter $sessionTokenMinter,
    ) {}

    public function exchange(Request $request): JsonResponse
    {
        $raw = $request->query('token', '');

        if (! is_string($raw) || $raw === '') {
            return $this->invalid($request);
        }

        $verified = $this->sessionTokenMinter->parse($raw);

        if ($verified === null || $verified->isExpired()) {
            return $this->invalid($request);
        }

        // Tenancy (gga finding 1): resolve the organization the TOKEN itself
        // claims, then scope BOTH the participant lookup and the consume
        // UPDATE below to it. `public_id` values are globally unique, so an
        // unscoped lookup could never accidentally RESOLVE another
        // organization's row — but a token whose `org` claim does not match
        // the participant's real organization is evidence of tampering or a
        // stale/forged claim, and must be refused exactly like any other
        // malformed token (`401 token_invalid`), not treated as a `410` on
        // a row this query then silently finds anyway.
        $orgBareId = PublicId::decode($verified->organizationPublicId, Organization::publicIdPrefix());
        $organization = $orgBareId === null ? null : Organization::query()->wherePublicId($orgBareId)->first();

        if ($organization === null) {
            return $this->invalid($request);
        }

        $bareId = PublicId::decode($verified->subject, Participant::publicIdPrefix());
        $participant = $bareId === null
            ? null
            : Participant::where('organization_id', $organization->id)->wherePublicId($bareId)->first();

        if ($participant === null) {
            return $this->invalid($request);
        }

        if ($participant->session_token_jti !== $verified->jti) {
            return $this->consumed($request);
        }

        // Atomic compare-and-clear: only succeeds while `organization_id`,
        // `session_token_jti` AND `status` STILL match what was just read —
        // folding the lifecycle check into this SAME statement (gga finding
        // 1) closes the window a separate "is it still pending?" read would
        // leave open: a status transition landing between that read and this
        // write could otherwise hand out a candidate JWT for an interview
        // that is no longer `pending`. A concurrent second exchange of the
        // SAME token loses this race too, for the identical reason (SPEC.md
        // §3.5 "single-use") — both cases answer `410 token_consumed`
        // rather than a second/late candidate JWT.
        $consumed = Participant::where('id', $participant->id)
            ->where('organization_id', $organization->id)
            ->where('session_token_jti', $verified->jti)
            ->where('status', 'in_attesa')
            ->update(['session_token_jti' => null]);

        if ($consumed === 0) {
            return $this->consumed($request);
        }

        // InterviewEvent IS a TenantModel — this controller runs on the
        // PUBLIC, unauthenticated embed-exchange route with no ambient
        // TenantContext (no TenantContext/PublicApiTenantContext middleware
        // reaches it — see this class's own docblock), so its `creating`
        // stamp would otherwise throw `MissingTenantContextException`. The
        // SAME pattern every console command and queued job in this
        // codebase already uses to write a TenantModel row outside an HTTP
        // request's own tenant-scoped middleware.
        TenantContextScope::runFor($participant->organization_id, function () use ($participant): void {
            InterviewEvent::create([
                'participant_id' => $participant->id,
                'type' => 'token_consumed',
                'occurred_at' => now(),
            ]);
        });

        $accessToken = CandidateTokenFactory::mintCandidateToken($participant);

        // No `Set-Cookie` — G-32/T-TOK-008.
        return response()->json(['access_token' => $accessToken], 200);
    }

    private function invalid(Request $request): JsonResponse
    {
        return Problem::make($request, 401, 'token_invalid', 'Invalid session token');
    }

    private function consumed(Request $request): JsonResponse
    {
        return Problem::make($request, 410, 'token_consumed', 'Session token already consumed');
    }
}
