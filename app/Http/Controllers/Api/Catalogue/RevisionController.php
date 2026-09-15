<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Catalogue;

use App\Actions\Catalogue\PublishRevision;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalogue\CatalogueRevisionResource;
use App\Models\FrameworkCatalogRevision;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The revision lifecycle surface: read the open draft, publish it
 * (framework-catalogue-authoring PR3, D1/D3/D12). See `RoleController` for
 * the per-action 403 rationale — identical here.
 */
class RevisionController extends Controller
{
    private function isSuperadmin(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->is_superadmin === true;
    }

    /**
     * READ-ONLY (gga review finding, blocking): this used to auto-open a
     * draft on every call, which breaks HTTP safety on a GET — a prefetch,
     * retry, or monitoring probe from a superadmin session would clone
     * ~450 rows for a request nobody asked to be a write. Auto-open on
     * "first edit" still happens, correctly, where an edit actually
     * occurs: every `store()` action across the catalogue controllers
     * calls `OpenDraftRevision::open()` itself. This endpoint only reports
     * whatever is currently true — `null` when nothing is open yet.
     */
    public function current(Request $request): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        $revision = FrameworkCatalogRevision::where('state', 'draft')->first();

        return response()->json(['data' => $revision === null ? null : new CatalogueRevisionResource($revision)]);
    }

    /**
     * `422` with the FULL violations list on a failing sweep — one response
     * naming every problem, `state` left `draft` (design D3).
     */
    public function publish(Request $request, PublishRevision $action): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        /** @var User $actor */
        $actor = $request->user();

        $draft = FrameworkCatalogRevision::where('state', 'draft')->first();

        if ($draft === null) {
            abort(Response::HTTP_NOT_FOUND, 'no open draft revision to publish');
        }

        $violations = $action->publish($draft, $actor);

        if ($violations !== []) {
            return response()->json(['violations' => $violations], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => new CatalogueRevisionResource($draft->fresh())]);
    }
}
