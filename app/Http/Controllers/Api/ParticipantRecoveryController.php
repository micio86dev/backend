<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Participant\RecoverFailedParticipant;
use App\Exceptions\Participant\RecoveryRefused;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Policies\ParticipantPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ParticipantRecoveryController (participant-error-recovery, design D1/D4).
 *
 * Route: POST /api/participants/{id}/recover
 * Auth: auth:api + TenantContext
 *
 * Thin by design: authorize, validate, delegate to
 * `App\Actions\Participant\RecoverFailedParticipant` — the ONLY place the
 * atomic reset lives. This controller passes a raw int id and never issues a
 * bare static call on the model directly (arch guard
 * `AdminTenancySafetyArchTest.php:102-113` forbids that under
 * app/Http/Controllers/Api — see `ParticipantPolicy::MODEL`'s own docblock
 * for why the reference lives there instead).
 *
 * Failure order 403 -> 404 (design D4, matching `EntryLinkController`'s write
 * precedent — the inverse of `AdminParticipantReader`'s read order):
 * authorization runs BEFORE any participant is resolved, so a `viewer` never
 * learns whether a given id even exists in their org.
 *
 * REQ: Atomic Participant and Session Recovery from Errore,
 *      Recovery Authorization
 *      (openspec/changes/participant-error-recovery/specs/participant-sso/spec.md)
 */
final class ParticipantRecoveryController extends Controller
{
    public function __construct(
        private readonly RecoverFailedParticipant $action,
    ) {}

    public function store(Request $request, int $id): JsonResponse
    {
        // (D4) 403 before 404 — model-less ability check, mirrors
        // EntryLinkController's authorize('create', ...) call.
        $this->authorize('recover', ParticipantPolicy::MODEL);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User|null $actor */
        $actor = $request->user();

        try {
            $result = $this->action->handle($id, $actor?->id, $validated['reason'] ?? null);
        } catch (RecoveryRefused $e) {
            return response()->json(['reason' => $e->reason->value], 409);
        }

        return response()->json([
            'status' => $result->status,
            'competencies_reset' => $result->competenciesReset,
            'utterances_discarded' => $result->utterancesDiscarded,
        ], 200);
    }
}
