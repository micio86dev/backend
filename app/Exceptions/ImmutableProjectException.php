<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when code attempts to change an immutable field on an active Project (C4),
 * or when a forbidden status lifecycle transition is requested.
 *
 * Immutable fields (once status = 'active'): assessment_type, framework_version_id, role_code.
 * Forbidden lifecycle: active→draft, archived→active, archived→draft.
 *
 * render() returns HTTP 422 — primary enforcement is at the FormRequest layer;
 * this exception is the model-guard backstop for direct (non-HTTP) writes.
 */
class ImmutableProjectException extends Exception
{
    /**
     * Render the exception as an HTTP response.
     *
     * Returns HTTP 422 with a JSON error envelope consistent with C2/C3 conventions.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage() ?: 'This operation is not allowed on an active project.',
        ], 422);
    }
}
