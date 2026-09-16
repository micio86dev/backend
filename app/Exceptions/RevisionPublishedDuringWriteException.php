<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thrown when a catalogue-content write loses a race against a concurrent
 * publish, or against a concurrent discard of the same draft (framework-
 * catalogue-authoring PR4b, K3/K8).
 *
 * `BumpsRevisionContentVersion::withRevisionLockedForWrite()` takes the SAME
 * `SELECT ... FOR UPDATE` lock on the owning revision row that
 * `PublishRevision::publish()` and `DiscardUnusedDraftRevision::discard()`
 * already take, and re-checks the row's `state` once unblocked — a revision
 * that stopped being an open draft while this write waited refuses cleanly
 * HERE, before the actual INSERT/UPDATE/DELETE ever reaches
 * `framework_catalog_refuse_published_content_write()`'s own `FOR SHARE`
 * check and its uncaught SQLSTATE 23514 (K8's own uncaught-500 finding).
 *
 * 409, not 422 — this is a conflict with a concurrent state change, not a
 * malformed request; `errorCode()` carries a machine-readable code per
 * BEAI's machine-facing response policy (CLAUDE.md), matching
 * `UserGuardException`'s own shape.
 *
 * `render()` is a fallback for any invocation that does not explicitly
 * catch this — every catalogue-write controller action DOES catch it
 * explicitly (mirroring `PlatformUserController::deactivate()`'s own
 * `UserGuardException` catch), because Scramble infers a route's documented
 * responses from what the controller method VISIBLY does; a `render()`
 * reached only through an uncaught throw deep inside
 * `BumpsRevisionContentVersion::withRevisionLockedForWrite()` is invisible
 * to that static analysis.
 */
class RevisionPublishedDuringWriteException extends Exception
{
    public function errorCode(): string
    {
        return 'revision_published_during_write';
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => $this->errorCode(),
            'message' => $this->getMessage() ?: 'The catalogue revision changed state while this write was in progress. Reload the draft and retry.',
        ], 409);
    }
}
