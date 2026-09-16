<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Catalogue;

use App\Exceptions\RevisionPublishedDuringWriteException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogue\StoreDefaultQuestionRequest;
use App\Http\Requests\Catalogue\UpdateDefaultQuestionRequest;
use App\Http\Resources\Catalogue\CatalogueDefaultQuestionResource;
use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkDefaultQuestion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Superadmin CRUD over catalogue-level default questions, scoped to the open
 * draft revision (framework-catalogue-authoring PR4, catalogue-authoring
 * spec — "Catalogue-Level Default Questions Per Competency"). See
 * `RoleController` for the per-action 403 rationale and the `update()`
 * no-auto-open rationale — identical here.
 */
class DefaultQuestionController extends Controller
{
    private function isSuperadmin(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->is_superadmin === true;
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        $draft = FrameworkCatalogRevision::openDraft();
        $questions = $draft === null
            ? collect()
            : FrameworkDefaultQuestion::where('revision_id', $draft->id)
                ->orderBy('competency_id')->orderBy('position')->get();

        return CatalogueDefaultQuestionResource::collection($questions);
    }

    public function store(StoreDefaultQuestionRequest $request): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

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
        try {
            $question = FrameworkDefaultQuestion::withRevisionLockedForWrite(
                $draftId,
                fn (): FrameworkDefaultQuestion => FrameworkDefaultQuestion::create([...$request->validated(), 'revision_id' => $draftId]),
            );
        } catch (RevisionPublishedDuringWriteException $e) {
            return response()->json(['error' => $e->errorCode(), 'message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return (new CatalogueDefaultQuestionResource($question))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Never opens a draft (gga review finding on the sibling controllers,
     * applied here from the start) — the target `$defaultQuestion` either
     * already belongs to an existing open draft or it does not exist to
     * update at all.
     */
    public function update(UpdateDefaultQuestionRequest $request, int $defaultQuestion): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        $draft = FrameworkCatalogRevision::openDraft();
        $target = $draft === null
            ? abort(Response::HTTP_NOT_FOUND)
            : FrameworkDefaultQuestion::where('revision_id', $draft->id)->findOrFail($defaultQuestion);

        try {
            FrameworkDefaultQuestion::withRevisionLockedForWrite($draft->id, fn (): bool => $target->update($request->validated()));
        } catch (RevisionPublishedDuringWriteException $e) {
            return response()->json(['error' => $e->errorCode(), 'message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return (new CatalogueDefaultQuestionResource($target->fresh()))->response();
    }

    /**
     * Pre-publish only — a default question's revision is, by definition,
     * always an open draft here (a published revision's content never
     * reaches this far: `findOrFail` scoped to the open draft 404s first).
     */
    public function destroy(Request $request, int $defaultQuestion): Response|JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        $draft = FrameworkCatalogRevision::openDraft();
        $target = $draft === null
            ? abort(Response::HTTP_NOT_FOUND)
            : FrameworkDefaultQuestion::where('revision_id', $draft->id)->findOrFail($defaultQuestion);

        try {
            FrameworkDefaultQuestion::withRevisionLockedForWrite($draft->id, fn (): ?bool => $target->delete());
        } catch (RevisionPublishedDuringWriteException $e) {
            return response()->json(['error' => $e->errorCode(), 'message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return response()->noContent();
    }
}
