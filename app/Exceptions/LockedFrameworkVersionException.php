<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when code attempts to update or delete a locked FrameworkVersion (C4).
 *
 * A locked FrameworkVersion has is_locked=true (pinned by at least one Project).
 * render() returns HTTP 422 — mirrors the ImmutableProjectException pattern.
 * Replaces the bare RuntimeException in FrameworkVersion.booted() (C3 placeholder).
 */
class LockedFrameworkVersionException extends Exception
{
    /**
     * Render the exception as an HTTP response.
     *
     * Returns HTTP 422 with a JSON error envelope consistent with C2/C3 conventions.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage() ?: 'This FrameworkVersion is locked and cannot be modified.',
        ], 422);
    }
}
