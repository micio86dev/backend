<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Catalogue;

use App\Exceptions\RevisionPublishedDuringWriteException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogue\StoreCompetencyRequest;
use App\Http\Requests\Catalogue\UpdateCompetencyRequest;
use App\Http\Resources\Catalogue\CatalogueCompetencyResource;
use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\User;
use App\Support\Catalogue\CatalogueConstraintViolation;
use App\Support\Superadmin\PlatformAuditWriter;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Superadmin CRUD over catalogue competencies, scoped to the open draft
 * revision (framework-catalogue-authoring PR3, D12). See `RoleController`
 * for the per-action 403 rationale and the `update()` no-auto-open
 * rationale — identical here.
 */
class CompetencyController extends Controller
{
    public function __construct(
        private readonly PlatformAuditWriter $auditWriter,
    ) {}

    private function isSuperadmin(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->is_superadmin === true;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        $draft = FrameworkCatalogRevision::openDraft();
        $competencies = $draft === null ? collect() : Competency::where('revision_id', $draft->id)->orderBy('code')->get();

        return CatalogueCompetencyResource::collection($competencies);
    }

    public function store(StoreCompetencyRequest $request): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        /** @var User $actor */
        $actor = $request->user();

        // H5 (framework-catalogue-authoring PR3b): reuse the draft id this
        // SAME request's `rules()` already resolved and validated against —
        // never call `OpenDraftRevision::open()` again here. See
        // `ResolvesOpenDraftRevision::openDraftRevisionId()`'s own docblock.
        $draftId = $request->openDraftRevisionId();

        // K3/K8 (framework-catalogue-authoring PR4b): locks the draft
        // revision row before writing — see `BumpsRevisionContentVersion::
        // withRevisionLockedForWrite()`'s own docblock. Caught explicitly so
        // Scramble documents the 409, matching `PlatformUserController::
        // deactivate()`'s own `UserGuardException` catch.
        //
        // The audit write runs INSIDE this same locked transaction, not
        // after it returns (framework-catalogue-authoring PR8): a write that
        // commits and an audit write that then fails must not leave the
        // client with a 500 for a change that actually saved, and
        // `PlatformAuditWriter::record()` deliberately does not swallow its
        // own errors the way the tenant-scoped `AuditRecorder` does — a
        // platform mutation with no audit row is exactly the gap this
        // capability exists to close, so it fails LOUD and rolls back with
        // the write it failed to record, rather than silently.
        try {
            $competency = Competency::withRevisionLockedForWrite(
                $draftId,
                function () use ($request, $draftId, $actor): Competency {
                    $competency = Competency::create([...$request->validated(), 'revision_id' => $draftId]);

                    $this->auditWriter->record(
                        actorId: $actor->id,
                        action: 'catalogue.competency.created',
                        subjectType: 'Competency',
                        subjectId: $competency->id,
                        before: null,
                        after: [...$request->validated(), 'revision_id' => $draftId],
                    );

                    return $competency;
                },
            );
        } catch (RevisionPublishedDuringWriteException $e) {
            return response()->json(['error' => $e->errorCode(), 'message' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (QueryException $e) {
            // Z4 (framework-catalogue-authoring, REQUIRED BEFORE ARCHIVE):
            // see `CatalogueConstraintViolation`'s own docblock — a
            // concurrent request naming the same `code` can pass THIS
            // request's own FormRequest uniqueness pre-check and still lose
            // to the DB's `framework_competencies_revision_code_unique`
            // constraint. An unrecognized violation is rethrown — still a
            // 500, on purpose.
            $errorCode = CatalogueConstraintViolation::toErrorCode($e);

            if ($errorCode === null) {
                throw $e;
            }

            return response()->json(['error' => $errorCode], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return (new CatalogueCompetencyResource($competency))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateCompetencyRequest $request, int $competency): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        /** @var User $actor */
        $actor = $request->user();

        $draft = FrameworkCatalogRevision::openDraft();
        $target = $draft === null
            ? abort(Response::HTTP_NOT_FOUND)
            : Competency::where('revision_id', $draft->id)->findOrFail($competency);

        try {
            Competency::withRevisionLockedForWrite($draft->id, function () use ($request, $target, $draft, $actor): bool {
                $saved = $target->update($request->validated());

                // `getPrevious()`/`getChanges()` — NOT `getOriginal()`, which
                // Eloquent's own `syncOriginal()` overwrites with the NEW
                // values by the time `update()` returns. `getPrevious()` is
                // captured by `syncChanges()` INSIDE `performUpdate()`,
                // strictly before that overwrite, so it is the actual OLD
                // value for exactly the keys that changed — the delta D13
                // asks for, not a before/after pair that always agrees.
                //
                // SKIPPED when nothing actually changed: a PATCH re-saving
                // identical values is not a mutation, and an audit row whose
                // only content is `revision_id` would misreport one.
                if ($target->getChanges() !== []) {
                    $this->auditWriter->record(
                        actorId: $actor->id,
                        action: 'catalogue.competency.updated',
                        subjectType: 'Competency',
                        subjectId: $target->id,
                        before: [...$target->getPrevious(), 'revision_id' => $draft->id],
                        after: [...$target->getChanges(), 'revision_id' => $draft->id],
                    );
                }

                return $saved;
            });
        } catch (RevisionPublishedDuringWriteException $e) {
            return response()->json(['error' => $e->errorCode(), 'message' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (QueryException $e) {
            // Z4 — see `store()`'s identical comment.
            $errorCode = CatalogueConstraintViolation::toErrorCode($e);

            if ($errorCode === null) {
                throw $e;
            }

            return response()->json(['error' => $errorCode], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return (new CatalogueCompetencyResource($target->fresh()))->response();
    }

    public function destroy(Request $request, int $competency): Response|JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        /** @var User $actor */
        $actor = $request->user();

        $draft = FrameworkCatalogRevision::openDraft();
        $target = $draft === null
            ? abort(Response::HTTP_NOT_FOUND)
            : Competency::where('revision_id', $draft->id)->findOrFail($competency);

        try {
            Competency::withRevisionLockedForWrite($draft->id, function () use ($target, $draft, $actor): ?bool {
                $before = [...$target->getAttributes(), 'revision_id' => $draft->id];
                $deleted = $target->delete();

                $this->auditWriter->record(
                    actorId: $actor->id,
                    action: 'catalogue.competency.deleted',
                    subjectType: 'Competency',
                    subjectId: $target->id,
                    before: $before,
                    after: null,
                );

                return $deleted;
            });
        } catch (RevisionPublishedDuringWriteException $e) {
            return response()->json(['error' => $e->errorCode(), 'message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return response()->noContent();
    }
}
