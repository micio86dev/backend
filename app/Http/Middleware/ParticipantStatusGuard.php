<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Participant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ParticipantStatusGuard middleware (C7a — Interview Session Mechanics).
 *
 * Blocks terminal-status participants from reaching any interview sub-route.
 * Applied ONLY to the nested `/api/candidate/interview/*` route group (FIX-7).
 *
 * Terminal statuses blocked: 'completato', 'errore'.
 *
 * Design rationale (FIX-7):
 *   This guard MUST be scoped to the 5 interview sub-routes ONLY, NOT to the
 *   parent C6 candidate group. Applying it to the parent would 403 a terminal
 *   candidate calling GET /api/candidate/session (a read endpoint, reasonable
 *   to allow even after completion). The guard revealing the candidate's OWN
 *   terminal status is acceptable — they already know it. Cross-org session
 *   existence is never leaked (resolveOwnedSession returns 404 for non-owned).
 *
 * Middleware ordering in the nested group:
 *   auth:api-candidate → TenantContextCandidate → SubstituteBindings → ParticipantStatusGuard
 *
 * The participant is already resolved by auth:api-candidate; this middleware
 * reads auth()->user() (a Participant instance) after the guard has run.
 *
 * REQ: Status guard — block terminal participants (C7a)
 */
final class ParticipantStatusGuard
{
    /**
     * Statuses that indicate a terminal participant.
     * A terminal participant MUST NOT be allowed to call any interview sub-route.
     *
     * @var list<string>
     */
    private const TERMINAL_STATUSES = ['completato', 'errore'];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Participant|null $participant */
        $participant = Auth::guard('api-candidate')->user();

        // Fail-closed: if no authenticated participant, let the auth middleware handle it.
        // This guard runs AFTER auth:api-candidate, so a null here is unexpected,
        // but we guard defensively.
        if ($participant === null) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        if (in_array($participant->status, self::TERMINAL_STATUSES, true)) {
            return response()->json(
                ['message' => 'Interview is no longer active for this participant.'],
                Response::HTTP_FORBIDDEN
            );
        }

        return $next($request);
    }
}
