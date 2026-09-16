<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Catalogue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogue\StoreRoleRequest;
use App\Http\Requests\Catalogue\UpdateRoleRequest;
use App\Http\Resources\Catalogue\CatalogueRoleResource;
use App\Models\FrameworkCatalogRevision;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Superadmin CRUD over catalogue roles, scoped to the open draft revision
 * (framework-catalogue-authoring PR3, D12).
 *
 * Every action repeats `abort_unless($this->isSuperadmin($request), 403)`
 * inline — copied verbatim from `PlatformUserController:87,102`, never
 * hidden behind a shared helper (Scramble infers responses from what a
 * controller VISIBLY does; burying this dropped the 403 from routes before,
 * see `PlatformUserController`'s own docblock and design D12).
 */
class RoleController extends Controller
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
        $roles = $draft === null ? collect() : Role::where('revision_id', $draft->id)->orderBy('code')->get();

        return CatalogueRoleResource::collection($roles);
    }

    /**
     * `responsibilities` is optional on `StoreRoleRequest` — matching the
     * seeder's own "not yet authored" sentinel (a blank `en` value, not an
     * ABSENT column: `responsibilities` has no DB default and is NOT
     * NULL). A `create()` call that never mentions the key writes SQL
     * NULL, not an empty locale map, so it is defaulted here to keep the
     * two "not yet authored" representations in agreement.
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        // H5 (framework-catalogue-authoring PR3b): reuse the draft id this
        // SAME request's `rules()` already resolved and validated against —
        // never call `OpenDraftRevision::open()` again here. See
        // `ResolvesOpenDraftRevision::openDraftRevisionId()`'s own docblock.
        $draftId = $request->openDraftRevisionId();

        $validated = $request->validated();
        $validated['responsibilities'] ??= ['en' => ''];

        $role = Role::create([...$validated, 'revision_id' => $draftId]);

        return (new CatalogueRoleResource($role))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Never opens a draft (gga review finding) — the target `$role` either
     * already belongs to an existing open draft or it does not exist to
     * update at all; opening a fresh clone here would copy ~450 rows only
     * to 404 immediately after, since a freshly-cloned row's id can never
     * equal the id named in the URL.
     */
    public function update(UpdateRoleRequest $request, int $role): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        $draft = FrameworkCatalogRevision::openDraft();
        $target = $draft === null
            ? abort(Response::HTTP_NOT_FOUND)
            : Role::where('revision_id', $draft->id)->findOrFail($role);

        $target->update($request->validated());

        return (new CatalogueRoleResource($target->fresh()))->response();
    }

    /**
     * Pre-publish only — a role's revision is, by definition, always an
     * open draft here (a published revision's content never reaches this
     * far: `findOrFail` scoped to the open draft 404s first).
     */
    public function destroy(Request $request, int $role): Response
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        $draft = FrameworkCatalogRevision::openDraft();
        $target = $draft === null
            ? abort(Response::HTTP_NOT_FOUND)
            : Role::where('revision_id', $draft->id)->findOrFail($role);

        $target->delete();

        return response()->noContent();
    }
}
