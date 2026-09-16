<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\RevisionWriteConflictCause;
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
 *
 * Z13 (R2-misleading-exception-name, REQUIRED BEFORE ARCHIVE): the class
 * NAME and its default `errorCode()` name only ONE of the two causes this
 * exception's own docblock (above) already discloses — a concurrent
 * DISCARD deleting the draft entirely throws the SAME
 * `revision_published_during_write` code as a concurrent PUBLISH, even
 * though nothing was "published". `Cause` distinguishes them; the CLASS
 * name is kept (renaming it would touch every catalogue controller's catch
 * block and the exported OpenAPI 409 shape for no behavioral gain), but the
 * machine-facing `errorCode()` and message now say which actually
 * happened.
 */
class RevisionPublishedDuringWriteException extends Exception
{
    public function __construct(
        string $message = '',
        private readonly RevisionWriteConflictCause $cause = RevisionWriteConflictCause::Published,
    ) {
        parent::__construct($message);
    }

    public function cause(): RevisionWriteConflictCause
    {
        return $this->cause;
    }

    public function errorCode(): string
    {
        return match ($this->cause) {
            RevisionWriteConflictCause::Published => 'revision_published_during_write',
            RevisionWriteConflictCause::Discarded => 'revision_discarded_during_write',
        };
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render(Request $request): JsonResponse
    {
        $defaultMessage = match ($this->cause) {
            RevisionWriteConflictCause::Published => 'The catalogue revision was published while this write was in progress. Reload the draft and retry.',
            RevisionWriteConflictCause::Discarded => 'The catalogue revision was discarded while this write was in progress. Reload the draft and retry.',
        };

        return response()->json([
            'error' => $this->errorCode(),
            'message' => $this->getMessage() ?: $defaultMessage,
        ], 409);
    }
}
