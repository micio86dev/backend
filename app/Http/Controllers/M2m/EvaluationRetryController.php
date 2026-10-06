<?php

declare(strict_types=1);

namespace App\Http\Controllers\M2m;

use App\Actions\Participant\AuthorizeEvaluationRetry;
use App\Actions\Participant\RetryActor;
use App\Exceptions\Participant\EvaluationRetryRefused;
use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EvaluationRetryController (scoring-retry-rt-b, design D6) — M2M surface.
 *
 * Route: POST /api/m2m/participants/{id}/retry
 * Auth: auth:api-m2m + ability:participants:retry
 *
 * The same thin shape as the operator controller over the same
 * `AuthorizeEvaluationRetry` action. What differs is who is calling: the
 * organization is the API client's own (`organization_id` of the authenticated
 * key, never the request), and the recorded actor is the client. The ability
 * middleware runs BEFORE the participant is resolved, so a client without
 * `participants:retry` gets 403 whether or not the id exists.
 *
 * This is the INTERNAL `/api/m2m` surface; there is no `/v1` retry endpoint.
 *
 * REQ: M2M Evaluation Retry Endpoint
 *      (openspec/changes/scoring-retry-rt-b/specs/m2m-auth/spec.md)
 */
final class EvaluationRetryController extends Controller
{
    public function __construct(
        private readonly AuthorizeEvaluationRetry $action,
    ) {}

    /**
     * Authorize the single re-interview of a pending evaluation.
     *
     * Requires the `participants:retry` ability.
     */
    public function store(Request $request, int $id): JsonResponse
    {
        /** @var ApiClient $client */
        $client = $request->user('api-m2m');

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $this->action->handle(
                $id,
                $client->organization_id,
                RetryActor::apiClient($client->id),
                $validated['reason'] ?? null,
            );
        } catch (EvaluationRetryRefused $e) {
            return response()->json(['reason' => $e->reason->value], 409);
        }

        // Scramble derives the response schema from this literal and cannot read a
        // property's type through the DTO, hence the key-level annotations.
        return response()->json([
            'status' => 'in_attesa',
            'entry_url' => $result->entryUrl,
            'expires_at' => $result->expiresAt->toIso8601String(),
            /** @var bool Whether BEAI queued the link by email to the candidate. */
            'email_sent' => $result->emailSent,
            /** @var list<string> Codes of the competencies that will be asked again. */
            'competencies_reset' => $result->competenciesReset,
        ], 200);
    }
}
