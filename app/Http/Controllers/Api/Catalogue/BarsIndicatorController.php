<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Catalogue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogue\StoreBarsIndicatorRequest;
use App\Http\Requests\Catalogue\UpdateBarsIndicatorRequest;
use App\Http\Resources\Catalogue\CatalogueBarsIndicatorResource;
use App\Models\BarsIndicator;
use App\Models\FrameworkCatalogRevision;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Superadmin CRUD over BARS indicators, scoped to the open draft revision
 * (framework-catalogue-authoring PR3, D12). See `RoleController` for the
 * per-action 403 rationale and the `update()` no-auto-open rationale —
 * identical here.
 */
class BarsIndicatorController extends Controller
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
        $indicators = $draft === null
            ? collect()
            : BarsIndicator::where('revision_id', $draft->id)->orderBy('competency_id')->orderBy('position')->get();

        return CatalogueBarsIndicatorResource::collection($indicators);
    }

    public function store(StoreBarsIndicatorRequest $request): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        // H5 (framework-catalogue-authoring PR3b): reuse the draft id this
        // SAME request's `rules()` already resolved and validated against —
        // never call `OpenDraftRevision::open()` again here. See
        // `ResolvesOpenDraftRevision::openDraftRevisionId()`'s own docblock.
        $draftId = $request->openDraftRevisionId();

        $indicator = BarsIndicator::create([...$request->validated(), 'revision_id' => $draftId]);

        return (new CatalogueBarsIndicatorResource($indicator))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateBarsIndicatorRequest $request, int $indicator): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        $draft = FrameworkCatalogRevision::openDraft();
        $target = $draft === null
            ? abort(Response::HTTP_NOT_FOUND)
            : BarsIndicator::where('revision_id', $draft->id)->findOrFail($indicator);

        $target->update($request->validated());

        return (new CatalogueBarsIndicatorResource($target->fresh()))->response();
    }

    public function destroy(Request $request, int $indicator): Response
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        $draft = FrameworkCatalogRevision::openDraft();
        $target = $draft === null
            ? abort(Response::HTTP_NOT_FOUND)
            : BarsIndicator::where('revision_id', $draft->id)->findOrFail($indicator);

        $target->delete();

        return response()->noContent();
    }
}
