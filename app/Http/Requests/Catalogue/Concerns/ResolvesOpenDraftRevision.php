<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue\Concerns;

use App\Actions\Catalogue\DiscardUnusedDraftRevision;
use App\Actions\Catalogue\OpenDraftRevision;
use App\Models\FrameworkCatalogRevision;
use Illuminate\Contracts\Validation\Validator;

/**
 * Every catalogue-write FormRequest scopes its rules (uniqueness, per-pair
 * counts) to THE OPEN DRAFT — auto-opened via `OpenDraftRevision` on first
 * edit if none exists yet (framework-catalogue-authoring PR3, task 12.1).
 *
 * Opened here, in the FormRequest, rather than in the controller: Laravel
 * resolves and validates a FormRequest BEFORE the controller method body
 * runs, and a rule like "refuse a 6th role" has to count roles in the
 * REVISION THE WRITE WILL ACTUALLY TARGET — which, for the very first edit
 * after a publish, does not exist until `OpenDraftRevision` clones it. A
 * controller-side open would run too late for this FormRequest's own rules
 * to see it. Cached per request instance — never opens twice for the same
 * FormRequest, so store()/update() see a stable id across `rules()` and the
 * controller body that runs after them.
 */
trait ResolvesOpenDraftRevision
{
    private ?int $resolvedDraftRevisionId = null;

    /**
     * Whether THIS request's own `openDraftRevisionId()` call is what
     * created the draft (`Model::wasRecentlyCreated`) rather than reusing
     * one that was already open. Read by `failedValidation()` below — never
     * set from a before-the-fact "did a draft already exist" check, which a
     * concurrent H4-style race could make lie: `wasRecentlyCreated` is
     * decided by the ACTUAL row `open()` handed back to THIS caller,
     * regardless of how the race was decided.
     */
    private bool $openedNewDraftThisRequest = false;

    /**
     * PUBLIC (framework-catalogue-authoring PR3b, H5): the controller's
     * `store()` action must reuse the SAME draft id this FormRequest already
     * validated against, never call `OpenDraftRevision::open()` a second
     * time. A second, independent call is not merely wasteful — a publish
     * landing in the gap between `rules()` running and the controller body
     * executing would make that second `open()` call see no open draft and
     * clone a BRAND NEW one, so the `exists`/uniqueness checks `rules()`
     * already validated (scoped to the FIRST draft) would refer to a
     * revision the INSERT no longer targets.
     */
    public function openDraftRevisionId(): int
    {
        if ($this->resolvedDraftRevisionId === null) {
            $draft = app(OpenDraftRevision::class)->open();
            $this->resolvedDraftRevisionId = $draft->id;
            $this->openedNewDraftThisRequest = $draft->wasRecentlyCreated;
        }

        return $this->resolvedDraftRevisionId;
    }

    /**
     * PUBLIC (Z12, framework-catalogue-authoring, REQUIRED BEFORE ARCHIVE):
     * `failedValidation()` below is not the only way a request that opened a
     * FRESH draft can end without ever writing to it — the controller's own
     * write can still fail AFTER validation passed (a 409
     * `RevisionPublishedDuringWriteException`, or a mapped 422 constraint
     * violation, Z4). Exposed so the CONTROLLER can discard the same draft
     * in those catch blocks too — see e.g. `RoleController::store()`.
     */
    public function openedNewDraftThisRequest(): bool
    {
        return $this->openedNewDraftThisRequest;
    }

    /**
     * Discard the draft THIS request created, and only that one (gga review
     * finding, blocking): `openDraftRevisionId()` runs inside `rules()`,
     * before a single rule is evaluated, so a 422 for a genuinely malformed
     * payload still cloned ~450 rows and committed them first. A request
     * that only CONTINUED an already-open draft (`$openedNewDraftThisRequest
     * === false`) never reaches the delete — that draft is someone else's
     * (or this same superadmin's OWN prior) real, in-progress work.
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->openedNewDraftThisRequest && $this->resolvedDraftRevisionId !== null) {
            app(DiscardUnusedDraftRevision::class)->discard($this->resolvedDraftRevisionId);
        }

        parent::failedValidation($validator);
    }

    /**
     * READ-ONLY counterpart for UPDATE requests (gga review finding,
     * blocking): `openDraftRevisionId()` above CLONES a new draft when none
     * is open, which is correct for a STORE (there is genuinely nothing to
     * write into otherwise) but wrong for an UPDATE — the target row named
     * in the URL either already belongs to an existing draft or it does
     * not exist to update at all, and validating a PATCH against a draft
     * this same request just conjured (whose freshly-cloned rows can never
     * match the id in the URL) always 404s downstream regardless, at the
     * cost of copying ~450 rows on every such request. Returns `null` when
     * no draft is open; callers scope a rule to it only when non-null and
     * let the controller's own `findOrFail` produce the 404.
     */
    protected function existingOpenDraftRevisionId(): ?int
    {
        return FrameworkCatalogRevision::openDraft()?->id;
    }
}
