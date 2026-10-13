<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicApi;

use App\Enums\ApiKeyMode;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Participant;
use App\Support\PublicApi\ApiMode;
use App\Support\PublicApi\HostedInterviewUrlComposer;
use App\Support\PublicApi\InterviewStatus;
use App\Support\PublicApi\Problem;
use App\Support\PublicApi\PublicId;
use App\Support\PublicApi\SessionTokenMinter;
use App\Support\Tenancy\TenantResolver;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /v1/interviews/{id}/session-tokens`.
 *
 * Mints a new session token and revokes any unconsumed previous one: overwriting
 * `participants.session_token_jti` is the revocation, because the embed exchange only
 * accepts a token whose `jti` matches that column's current value.
 *
 * Allowed only while `pending`; every other status answers `409 invalid_state`. Not
 * idempotent (no `Idempotency-Key`): a duplicate mint always revokes the previous token.
 *
 * Minting stays allowed for a pending participant whose project was later soft-deleted:
 * only `status` is read, and creating an enrolment (not minting) is what requires a live project.
 */
final class SessionTokenController extends Controller
{
    public function __construct(
        private readonly SessionTokenMinter $sessionTokenMinter,
        private readonly HostedInterviewUrlComposer $hostedInterviewUrlComposer,
    ) {}

    /**
     * Create a session token.
     *
     * Mints a session token and the hosted interview URL for a pending interview. Minting a new token revokes any previous token that has not been used yet. Any other interview status answers `409 invalid_state`; a malformed or unknown interview id answers `404 not_found`.
     */
    #[Response(409, description: 'The interview is not in a state that allows a new session token (code=invalid_state).', type: Problem::PROBLEM_SHAPE)]
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
            : Participant::where('organization_id', $organization->id)
                ->where('mode', app(ApiMode::class)->isTest() ? ApiKeyMode::Test : ApiKeyMode::Live)
                ->wherePublicId($bareId)
                ->first();

        if ($participant === null) {
            abort(404);
        }

        if (InterviewStatus::fromStored($participant->status) !== InterviewStatus::Pending) {
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
