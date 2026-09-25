<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Participant;
use App\Support\PublicApi\HostedInterviewUrlComposer;
use App\Support\PublicApi\Problem;
use App\Support\PublicApi\PublicId;
use App\Support\PublicApi\SessionTokenMinter;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /v1/interviews/{id}/session-tokens` — BEAI Public API (public-api
 * step 5), SPEC.md §3.5, `openapi.yaml`'s `createSessionToken` operation.
 *
 * Mints a NEW session token and revokes any unconsumed previous one — the
 * simple overwrite of `participants.session_token_jti` below IS the
 * revocation (SPEC.md §3.5 "Minting a new token revokes previous unconsumed
 * ones."): the embed exchange only ever accepts a token whose `jti` matches
 * this column's CURRENT value, so the previous `jti` stops matching the
 * instant this write lands.
 *
 * Allowed only while `pending` — every other status answers
 * `409 invalid_state` (SPEC.md §3.3 table). NOT idempotent — no
 * `Idempotency-Key` support (G-16): a duplicate mint is never a safe
 * replay, it always revokes whatever token came before it.
 *
 * gga round 3 finding 1 (decided, not an oversight): mint stays ALLOWED for
 * a `pending` participant whose project was later soft-deleted — this
 * controller never reads `project` at all, only `status`, so a trashed
 * project simply never enters the decision. The candidate may still
 * complete the interview they were already enrolled in; whether the
 * PROJECT still exists is a fact about `POST /v1/interviews` (create,
 * which stays gated on a live project — see
 * `InterviewController::resolveProject()`'s own docblock), not about
 * minting a token for an enrolment that already exists.
 */
final class SessionTokenController extends Controller
{
    public function __construct(
        private readonly SessionTokenMinter $sessionTokenMinter,
        private readonly HostedInterviewUrlComposer $hostedInterviewUrlComposer,
    ) {}

    public function store(Request $request, string $interview): JsonResponse
    {
        $orgId = app(TenantResolver::class)->getOrgId();
        $organization = $orgId === null ? null : Organization::query()->find($orgId);

        if ($organization === null) {
            abort(404);
        }

        $bareId = PublicId::decode($interview, Participant::publicIdPrefix());
        $participant = $bareId === null
            ? null
            : Participant::where('organization_id', $organization->id)->wherePublicId($bareId)->first();

        if ($participant === null) {
            abort(404);
        }

        if ($participant->status !== 'in_attesa') {
            return Problem::make(
                $request, 409, 'invalid_state', 'Invalid state',
                'Session tokens can only be minted while the interview is pending.',
            );
        }

        $minted = $this->sessionTokenMinter->mint($participant);

        $participant->forceFill(['session_token_jti' => $minted->jti])->save();

        $hostedUrl = $this->hostedInterviewUrlComposer->compose($minted->token, $participant->language);

        return response()->json([
            'session_token' => $minted->token,
            'expires_at' => $minted->expiresAt->toISOString(),
            'hosted_url' => $hostedUrl,
        ], 201);
    }
}
