<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Catalogue;

use App\Actions\Catalogue\DiscardUnusedDraftRevision;
use App\Exceptions\RevisionPublishedDuringWriteException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogue\StoreBarsIndicatorRequest;
use App\Http\Requests\Catalogue\UpdateBarsIndicatorRequest;
use App\Http\Resources\Catalogue\CatalogueBarsIndicatorResource;
use App\Models\BarsIndicator;
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
 * Superadmin CRUD over BARS indicators; writes are scoped to the open draft
 * revision (framework-catalogue-authoring PR3, D12). See `RoleController`
 * for the read-side revision resolution, the per-action 403 rationale and
 * the `update()` no-auto-open rationale — identical here.
 */
class BarsIndicatorController extends Controller
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

        $revision = FrameworkCatalogRevision::viewable();
        $indicators = $revision === null
            ? collect()
            : BarsIndicator::where('revision_id', $revision->id)->orderBy('competency_id')->orderBy('position')->get();

        return CatalogueBarsIndicatorResource::collection($indicators);
    }

    public function store(StoreBarsIndicatorRequest $request): JsonResponse
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
        // The audit write runs INSIDE this same locked transaction
        // (framework-catalogue-authoring PR8) — see `CompetencyController::
        // store()`'s identical comment for why.
        try {
            $indicator = BarsIndicator::withRevisionLockedForWrite(
                $draftId,
                function () use ($request, $draftId, $actor): BarsIndicator {
                    $indicator = BarsIndicator::create([...$request->validated(), 'revision_id' => $draftId]);

                    $this->auditWriter->record(
                        actorId: $actor->id,
                        action: 'catalogue.indicator.created',
                        subjectType: 'BarsIndicator',
                        subjectId: $indicator->id,
                        before: null,
                        after: [...$request->validated(), 'revision_id' => $draftId],
                    );

                    return $indicator;
                },
            );
        } catch (RevisionPublishedDuringWriteException $e) {
            return response()->json(['error' => $e->errorCode(), 'message' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (QueryException $e) {
            // Z4 (framework-catalogue-authoring, REQUIRED BEFORE ARCHIVE):
            // see `CatalogueConstraintViolation`'s own docblock — a
            // concurrent request can pass this SAME request's FormRequest
            // pre-check and still lose to the DB layer's own backstop
            // (the pair-cap trigger, the position partial unique index).
            // An unrecognized violation is rethrown — still a 500, on
            // purpose.
            $errorCode = CatalogueConstraintViolation::toErrorCode($e);

            if ($errorCode === null) {
                throw $e;
            }

            return response()->json(['error' => $errorCode], Response::HTTP_UNPROCESSABLE_ENTITY);
        } finally {
            // Z12 — see `RoleController::store()`'s identical comment.
            if ($request->openedNewDraftThisRequest()) {
                app(DiscardUnusedDraftRevision::class)->discard($draftId);
            }
        }

        return (new CatalogueBarsIndicatorResource($indicator))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateBarsIndicatorRequest $request, int $indicator): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        /** @var User $actor */
        $actor = $request->user();

        $draft = FrameworkCatalogRevision::openDraft();
        $target = $draft === null
            ? abort(Response::HTTP_NOT_FOUND)
            : BarsIndicator::where('revision_id', $draft->id)->findOrFail($indicator);

        try {
            BarsIndicator::withRevisionLockedForWrite($draft->id, function () use ($request, $target, $draft, $actor): bool {
                $saved = $target->update($request->validated());

                // `getPrevious()`/`getChanges()` — see `CompetencyController::
                // update()`'s identical comment for why `getOriginal()` is
                // wrong here, and for why a genuinely no-op save is skipped.
                if ($target->getChanges() !== []) {
                    $this->auditWriter->record(
                        actorId: $actor->id,
                        action: 'catalogue.indicator.updated',
                        subjectType: 'BarsIndicator',
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

        return (new CatalogueBarsIndicatorResource($target->fresh()))->response();
    }

    public function destroy(Request $request, int $indicator): Response|JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        /** @var User $actor */
        $actor = $request->user();

        $draft = FrameworkCatalogRevision::openDraft();
        $target = $draft === null
            ? abort(Response::HTTP_NOT_FOUND)
            : BarsIndicator::where('revision_id', $draft->id)->findOrFail($indicator);

        try {
            BarsIndicator::withRevisionLockedForWrite($draft->id, function () use ($target, $draft, $actor): ?bool {
                $before = [...$target->getAttributes(), 'revision_id' => $draft->id];
                $deleted = $target->delete();

                $this->auditWriter->record(
                    actorId: $actor->id,
                    action: 'catalogue.indicator.deleted',
                    subjectType: 'BarsIndicator',
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
