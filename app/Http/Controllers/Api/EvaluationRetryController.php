<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Participant\AuthorizeEvaluationRetry;
use App\Actions\Participant\RetryActor;
use App\Exceptions\Participant\EvaluationRetryRefused;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Policies\ParticipantPolicy;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EvaluationRetryController (scoring-retry-rt-b, design D6) — operator surface.
 *
 * Route: POST /api/participants/{id}/retry
 * Auth: auth:api + TenantContext
 *
 * Thin by design: authorize, validate, delegate to
 * `App\Actions\Participant\AuthorizeEvaluationRetry`, the ONLY place the retry
 * decision lives (the M2M controller calls the same action). The organization
 * comes from the authenticated tenant, never from the request. This controller
 * passes a raw int id and issues no bare static call on the model (arch guard
 * `AdminTenancySafetyArchTest` forbids it under app/Http/Controllers/Api).
 *
 * Failure order 403 -> 404 (matches `ParticipantRecoveryController`): the policy
 * check runs BEFORE any participant is resolved, so a viewer never learns whether
 * an id exists. A participant of another organization is a 404 identical to an
 * unknown id (`ModelNotFoundException`, rendered by the global handler).
 *
 * The response carries the single-use entry link under the name `entry_url` on
 * purpose: the backoffice Sentry scrubber redacts that key. The link is never
 * logged and never audited here or in the action.
 *
 * REQ: Evaluation Retry Authorization Action, Evaluation Retry Refusal Guards
 *      (openspec/changes/scoring-retry-rt-b/specs/participant-sso/spec.md)
 */
final class EvaluationRetryController extends Controller
{
    public function __construct(
        private readonly AuthorizeEvaluationRetry $action,
        private readonly TenantResolver $tenant,
    ) {}

    /**
     * Authorize the single re-interview of a pending evaluation.
     *
     * Re-opens the participant for the competencies whose result is invalid and returns
     * a single-use link. Allowed once per participant, for a completed interview whose
     * evaluation is still pending.
     */
    public function store(Request $request, int $id): JsonResponse
    {
        $this->authorize('retry', ParticipantPolicy::MODEL);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->user();

        // A caller with no organization in scope (a superadmin not acting for
        // one) has no participant to authorize: same 404 as an unknown id.
        $organizationId = $this->tenant->getOrgId();
        abort_if($organizationId === null, 404);

        try {
            $result = $this->action->handle(
                $id,
                $organizationId,
                RetryActor::user($user->id),
                $validated['reason'] ?? null,
            );
        } catch (EvaluationRetryRefused $e) {
            return response()->json(['reason' => $e->reason->value], 409);
        }

        // Scramble derives the response schema from this literal and cannot read a
        // property's type through the DTO, hence the key-level annotations.
        return response()->json([
            /** @var string The participant's new lifecycle status. */
            'status' => $result->status,
            'entry_url' => $result->entryUrl,
            'expires_at' => $result->expiresAt->toIso8601String(),
            /** @var bool Whether BEAI queued the link by email to the candidate. */
            'email_sent' => $result->emailSent,
            /** @var list<string> Codes of the competencies that will be asked again. */
            'competencies_reset' => $result->competenciesReset,
        ], 200);
    }
}
