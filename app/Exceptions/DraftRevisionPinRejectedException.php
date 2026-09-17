<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when a FrameworkVersion is created or resolved against a `draft`
 * catalogue revision (framework-catalogue-authoring PR1, D1).
 *
 * A pin must never point at content that can still change under it — only a
 * `published` revision may be pinned. Mirrors LockedFrameworkVersionException's
 * shape: render() returns HTTP 422, consistent with C2/C3/C4 conventions.
 */
class DraftRevisionPinRejectedException extends Exception
{
    /**
     * Render the exception as an HTTP response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage() ?: 'Only a published catalogue revision may be pinned.',
        ], 422);
    }
}
