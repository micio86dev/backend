<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when a `published` FrameworkCatalogRevision row is mutated
 * (framework-catalogue-authoring PR1, D2 — "published, immutable, no
 * exceptions").
 *
 * Mirrors LockedFrameworkVersionException's shape: render() returns HTTP
 * 422, consistent with C2/C3/C4 conventions. This guards the REVISION row
 * itself (state/is_baseline/label/published_at); immutability of the
 * catalogue CONTENT a published revision owns (competencies, roles, BARS
 * indicators, default questions) is PR 3's `PublishedRevisionImmutabilityTest`
 * — a different, later invariant over different tables.
 */
class PublishedRevisionImmutableException extends Exception
{
    /**
     * Render the exception as an HTTP response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage() ?: 'A published catalogue revision is immutable.',
        ], 422);
    }
}
