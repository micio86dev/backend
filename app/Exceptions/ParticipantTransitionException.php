<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when code attempts an illegal status transition on a Participant (C6).
 *
 * Allowed transitions:
 *   in_attesa → in_corso
 *   in_corso  → in_valutazione
 *   in_valutazione → completato | errore
 *
 * render() returns HTTP 422 — primary enforcement is at the controller/exchange
 * layer; this exception is the model-guard backstop for direct (non-HTTP) writes.
 *
 * Mirrors ImmutableProjectException / LockedFrameworkVersionException pattern.
 *
 * REQ: Participant Model Lifecycle Guard
 */
class ParticipantTransitionException extends Exception
{
    /**
     * Render the exception as an HTTP response.
     *
     * Returns HTTP 422 with a JSON error envelope consistent with C2/C4 conventions.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage() ?: 'Invalid status transition.',
        ], 422);
    }
}
