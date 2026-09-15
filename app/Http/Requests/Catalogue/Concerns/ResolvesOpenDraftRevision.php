<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalogue\Concerns;

use App\Actions\Catalogue\OpenDraftRevision;
use App\Models\FrameworkCatalogRevision;

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

    protected function openDraftRevisionId(): int
    {
        if ($this->resolvedDraftRevisionId === null) {
            $this->resolvedDraftRevisionId = app(OpenDraftRevision::class)->open()->id;
        }

        return $this->resolvedDraftRevisionId;
    }

    protected function openDraftRevision(): FrameworkCatalogRevision
    {
        return FrameworkCatalogRevision::findOrFail($this->openDraftRevisionId());
    }
}
