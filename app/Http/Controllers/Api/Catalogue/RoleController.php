<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Catalogue;

use App\Actions\Catalogue\DiscardUnusedDraftRevision;
use App\Exceptions\RevisionPublishedDuringWriteException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogue\StoreRoleRequest;
use App\Http\Requests\Catalogue\UpdateRoleCompetenciesRequest;
use App\Http\Requests\Catalogue\UpdateRoleRequest;
use App\Http\Resources\Catalogue\CatalogueRoleResource;
use App\Models\FrameworkCatalogRevision;
use App\Models\Role;
use App\Models\User;
use App\Support\Catalogue\CatalogueConstraintViolation;
use App\Support\Superadmin\PlatformAuditWriter;
use Illuminate\Database\QueryException;
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
        // Eager-loaded (framework-catalogue-authoring PR8b, gga review
        // finding): `CatalogueRoleResource::competency_ids` reads this
        // relation for every role it serializes — unloaded, that is one
        // query per role.
        $roles = $draft === null ? collect() : Role::with('competencies')->where('revision_id', $draft->id)->orderBy('code')->get();

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

        /** @var User $actor */
        $actor = $request->user();

        // H5 (framework-catalogue-authoring PR3b): reuse the draft id this
        // SAME request's `rules()` already resolved and validated against —
        // never call `OpenDraftRevision::open()` again here. See
        // `ResolvesOpenDraftRevision::openDraftRevisionId()`'s own docblock.
        $draftId = $request->openDraftRevisionId();

        $validated = $request->validated();
        $validated['responsibilities'] ??= ['en' => ''];

        // K3/K8 (framework-catalogue-authoring PR4b): locks the draft
        // revision row before writing, closing the race with a concurrent
        // discard/publish — see `BumpsRevisionContentVersion::
        // withRevisionLockedForWrite()`'s own docblock. Caught explicitly
        // (not left to the exception's own `render()`) so Scramble documents
        // the 409, matching `PlatformUserController::deactivate()`'s own
        // `UserGuardException` catch.
        // The audit write runs INSIDE this same locked transaction
        // (framework-catalogue-authoring PR8) — see `CompetencyController::
        // store()`'s identical comment for why.
        try {
            $role = Role::withRevisionLockedForWrite(
                $draftId,
                function () use ($validated, $draftId, $actor): Role {
                    $role = Role::create([...$validated, 'revision_id' => $draftId]);

                    $this->auditWriter->record(
                        actorId: $actor->id,
                        action: 'catalogue.role.created',
                        subjectType: 'Role',
                        subjectId: $role->id,
                        before: null,
                        after: [...$validated, 'revision_id' => $draftId],
                    );

                    return $role;
                },
            );
        } catch (RevisionPublishedDuringWriteException $e) {
            return response()->json(['error' => $e->errorCode(), 'message' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (QueryException $e) {
            // Z4 (framework-catalogue-authoring, REQUIRED BEFORE ARCHIVE):
            // see `CatalogueConstraintViolation`'s own docblock — a
            // concurrent request naming the same `code` can pass THIS
            // request's own FormRequest uniqueness pre-check and still lose
            // to the DB's `framework_roles_revision_code_unique` constraint.
            // An unrecognized violation is rethrown — still a 500, on
            // purpose.
            $errorCode = CatalogueConstraintViolation::toErrorCode($e);

            if ($errorCode === null) {
                throw $e;
            }

            return response()->json(['error' => $errorCode], Response::HTTP_UNPROCESSABLE_ENTITY);
        } finally {
            // Z12 (R3-draft-orphan-on-post-validation-failure, REQUIRED
            // BEFORE ARCHIVE): `failedValidation()` (`ResolvesOpenDraftRevision`)
            // only discards a freshly-cloned draft when VALIDATION itself
            // failed — a write that fails AFTER validation passed (the two
            // catches above) left that same clone sitting in the one-draft
            // slot forever, holding nothing. `discard()` is self-guarding
            // (a no-op once `content_version !== 0`), so calling it here
            // unconditionally — including on the SUCCESS path, where it
            // correctly does nothing — is safe.
            if ($request->openedNewDraftThisRequest()) {
                app(DiscardUnusedDraftRevision::class)->discard($draftId);
            }
        }

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

        /** @var User $actor */
        $actor = $request->user();

        $draft = FrameworkCatalogRevision::openDraft();
        $target = $draft === null
            ? abort(Response::HTTP_NOT_FOUND)
            : Role::where('revision_id', $draft->id)->findOrFail($role);

        try {
            Role::withRevisionLockedForWrite($draft->id, function () use ($request, $target, $draft, $actor): bool {
                $saved = $target->update($request->validated());

                // `getPrevious()`/`getChanges()` — see `CompetencyController::
                // update()`'s identical comment for why `getOriginal()` is
                // wrong here, and for why a genuinely no-op save is skipped.
                if ($target->getChanges() !== []) {
                    $this->auditWriter->record(
                        actorId: $actor->id,
                        action: 'catalogue.role.updated',
                        subjectType: 'Role',
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

        return (new CatalogueRoleResource($target->fresh()))->response();
    }

    /**
     * `PUT /catalogue/roles/{role}/competencies` (framework-catalogue-authoring
     * PR8b). Replaces the role's ENTIRE competency set in one locked write —
     * attach, detach and reorder are the same `sync()` call against a pivot
     * that already carries a `position` column, never three endpoints. Never
     * auto-opens a draft — see `UpdateRoleCompetenciesRequest`'s own
     * no-auto-open rationale, identical to `update()` above.
     */
    public function updateCompetencies(UpdateRoleCompetenciesRequest $request, int $role): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        /** @var User $actor */
        $actor = $request->user();

        $draft = FrameworkCatalogRevision::openDraft();
        $target = $draft === null
            ? abort(Response::HTTP_NOT_FOUND)
            : Role::where('revision_id', $draft->id)->findOrFail($role);

        /** @var list<int> $rawIds */
        $rawIds = $request->validated('competency_ids');
        $competencyIds = array_map('intval', $rawIds);

        try {
            Role::withRevisionLockedForWrite($draft->id, function () use ($target, $draft, $actor, $competencyIds): void {
                $before = array_map('intval', array_values($target->competencies()->pluck('framework_competencies.id')->all()));

                $positioned = [];
                foreach ($competencyIds as $position => $competencyId) {
                    $positioned[$competencyId] = ['position' => $position];
                }

                // `withPivotValue('revision_id', ...)` on `Role::competencies()`
                // (design D1, PR4b K1) already names THIS role's own revision
                // on every write through this relation — never passed
                // explicitly here.
                $target->competencies()->sync($positioned);

                // Guarded by the SAME "did anything actually change" check
                // as the audit write below (gga review finding): an
                // unchanged PUT re-submitting the current set is not a
                // mutation, and bumping the counter regardless would make
                // `DiscardUnusedDraftRevision` treat a genuinely untouched
                // draft as written into. `sync()` writes through the pivot
                // directly and fires no `saved`/`deleted` event on `Role`
                // itself, so the bump is explicit whenever it does apply —
                // see `BumpsRevisionContentVersion::
                // bumpRevisionContentVersionForRevision()`'s own docblock.
                if ($before !== $competencyIds) {
                    Role::bumpRevisionContentVersionForRevision($draft->id);

                    $this->auditWriter->record(
                        actorId: $actor->id,
                        action: 'catalogue.role.competencies.updated',
                        subjectType: 'Role',
                        subjectId: $target->id,
                        before: ['competency_ids' => $before, 'revision_id' => $draft->id],
                        after: ['competency_ids' => $competencyIds, 'revision_id' => $draft->id],
                    );
                }
            });
        } catch (RevisionPublishedDuringWriteException $e) {
            return response()->json(['error' => $e->errorCode(), 'message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return (new CatalogueRoleResource($target->fresh()))->response();
    }

    /**
     * Pre-publish only — a role's revision is, by definition, always an
     * open draft here (a published revision's content never reaches this
     * far: `findOrFail` scoped to the open draft 404s first).
     */
    public function destroy(Request $request, int $role): Response|JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        /** @var User $actor */
        $actor = $request->user();

        $draft = FrameworkCatalogRevision::openDraft();
        $target = $draft === null
            ? abort(Response::HTTP_NOT_FOUND)
            : Role::where('revision_id', $draft->id)->findOrFail($role);

        try {
            Role::withRevisionLockedForWrite($draft->id, function () use ($target, $draft, $actor): ?bool {
                $before = [...$target->getAttributes(), 'revision_id' => $draft->id];
                $deleted = $target->delete();

                $this->auditWriter->record(
                    actorId: $actor->id,
                    action: 'catalogue.role.deleted',
                    subjectType: 'Role',
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
