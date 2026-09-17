<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Catalogue;

use App\Actions\Catalogue\DiscardOpenDraftRevision;
use App\Actions\Catalogue\OpenDraftRevision;
use App\Actions\Catalogue\PublishRevision;
use App\Exceptions\RevisionPublishedDuringWriteException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalogue\CatalogueRevisionResource;
use App\Models\FrameworkCatalogRevision;
use App\Models\User;
use App\Support\Superadmin\PlatformAuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * The revision lifecycle surface: read the revision the catalogue shows,
 * open a draft, publish it (framework-catalogue-authoring PR3, D1/D3/D12).
 * See `RoleController` for the per-action 403 rationale — identical here.
 */
class RevisionController extends Controller
{
    public function __construct(
        private readonly PlatformAuditWriter $auditWriter,
    ) {}

    private function isSuperadmin(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->is_superadmin === true;
    }

    /**
     * READ-ONLY: a GET never opens a draft — a prefetch, retry, or
     * monitoring probe must not clone ~450 rows. Reports the revision the
     * catalogue lists currently return (`FrameworkCatalogRevision::
     * viewable()`): the open draft (`editable: true`), otherwise the latest
     * published revision (`editable: false`). `null` only before anything
     * has been published.
     */
    public function current(Request $request): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        $revision = FrameworkCatalogRevision::viewable();

        return response()->json(['data' => $revision === null ? null : new CatalogueRevisionResource($revision)]);
    }

    /**
     * Opens the draft a superadmin edits, cloned from the latest published
     * revision, or returns the one already open — idempotent, `201` when
     * this call created it and `200` when it continued an existing one.
     * After it, every catalogue list returns the draft's own row ids, which
     * are the only ids the write endpoints accept.
     *
     * Audited only when a draft was actually created, inside the same
     * transaction as the clone: returning an existing draft changes nothing,
     * and a clone whose audit row fails to write rolls back with it rather
     * than leaving an unaudited platform mutation behind.
     * `404` when no published revision exists to clone from.
     */
    public function openDraft(Request $request, OpenDraftRevision $action): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        /** @var User $actor */
        $actor = $request->user();

        $draft = DB::transaction(function () use ($action, $actor): FrameworkCatalogRevision {
            $draft = $action->open();

            if ($draft->wasRecentlyCreated) {
                $this->auditWriter->record(
                    actorId: $actor->id,
                    action: 'revision.draft_opened',
                    subjectType: 'FrameworkCatalogRevision',
                    subjectId: $draft->id,
                    before: null,
                    after: ['revision_id' => $draft->id, 'parent_revision_id' => $draft->parent_revision_id],
                );
            }

            return $draft;
        });

        if ($draft->wasRecentlyCreated) {
            return (new CatalogueRevisionResource($draft))->response()->setStatusCode(Response::HTTP_CREATED);
        }

        return (new CatalogueRevisionResource($draft))->response();
    }

    /**
     * Abandons the open draft entirely — every uncommitted role, competency,
     * BARS indicator and default question it holds. The catalogue reverts to
     * showing the latest PUBLISHED revision, read-only, exactly the state it
     * was in before `openDraft()` was ever called.
     *
     * `404` when no draft is open — nothing to discard. `409` when a
     * concurrent write or publish raced this request for the revision lock
     * (`RevisionPublishedDuringWriteException`, same contract every other
     * catalogue write already answers with).
     */
    public function discard(Request $request, DiscardOpenDraftRevision $action): JsonResponse
    {
        abort_unless($this->isSuperadmin($request), Response::HTTP_FORBIDDEN);

        $draft = FrameworkCatalogRevision::openDraft();

        if ($draft === null) {
            abort(Response::HTTP_NOT_FOUND, 'no open draft revision to discard');
        }

        /** @var User $actor */
        $actor = $request->user();

        try {
            $action->discard($draft, $actor);
        } catch (RevisionPublishedDuringWriteException $e) {
            return response()->json(['error' => $e->errorCode(), 'message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        $latest = FrameworkCatalogRevision::viewable();

        return response()->json(['data' => $latest === null ? null : new CatalogueRevisionResource($latest)]);
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

        $draft = FrameworkCatalogRevision::openDraft();

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
