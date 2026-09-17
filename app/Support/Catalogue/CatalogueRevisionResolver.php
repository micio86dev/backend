<?php

declare(strict_types=1);

namespace App\Support\Catalogue;

use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkVersion;
use App\Models\Project;
use RuntimeException;

/**
 * Resolves the ONE catalogue revision a tenant-facing read is allowed to see
 * (framework-catalogue-authoring PR3b, H1).
 *
 * Once a draft is open, every role/competency code exists TWICE — once in
 * the revision it was cloned from, once in the draft — because a draft is a
 * full row-set clone (D1), never a delta. A reader that resolves a role or
 * competency BY CODE with no revision filter is at the mercy of whichever
 * row PostgreSQL happens to return first: a live interview, a scoring run,
 * or a project-creation check could silently accept or read content a
 * superadmin has not published yet.
 *
 * Every tenant-facing catalogue reader that resolves by CODE — never one
 * that already holds a specific, previously-resolved numeric id, since
 * `role_id`/`competency_id` are revision-unique by construction (the
 * composite FKs on `framework_bars_indicators` and `framework_role_
 * competency` guarantee a row's `role_id`/`competency_id` and its own
 * `revision_id` always agree) — MUST resolve through this class instead of
 * querying `framework_roles`/`framework_competencies` unscoped.
 *
 * ONE rule decides every method here: an already-pinned entity NEVER
 * silently substitutes "whatever is latest right now" for its own missing
 * pin (CLAUDE.md ruling 3 — "a framework version is pinned once, never
 * retargeted"); "no entity to pin against at all" (catalogue browse before
 * a project exists, an as-yet-unvalidated submitted id) is the only case
 * that resolves to the latest PUBLISHED revision, never a draft.
 *
 * Two families, chosen by what the CALLER can safely do with a failure:
 *
 *   - `forProject()` / `forFrameworkVersion()` THROW when an entity exists
 *     but its pin does not resolve. Reserved for contexts that can and
 *     should surface a genuine data-integrity failure loudly — mirroring
 *     `AdminEvaluationSerializer::meta()`'s own posture for the identical
 *     failure class (a pinned FK that did not resolve) — such as an offline
 *     write path (`DemoWriter`) that must never write silently-wrong content.
 *   - `tryForProject()` / `tryForFrameworkVersion()` / `tryLatestPublished()`
 *     NEVER throw. An unresolvable pin degrades to `null` — never a
 *     substitute revision — so the caller can answer its OWN normal "not
 *     found" outcome (a 422, a 404, an empty catalogue, a stored-text
 *     fallback) instead of a 500. Every caller that runs inside `rules()`,
 *     a live candidate request, or any other context that must not 500 on
 *     an unexpected pin uses this family.
 */
final class CatalogueRevisionResolver
{
    /**
     * Every real revision id is a positive serial — never reachable by an
     * actual row, so a caller that scopes a query to this sentinel (after
     * `tryLatestPublished()` returns `null`, an unseeded platform) resolves
     * to an empty result rather than throwing. Shared here (R2-duplicated-
     * sentinel) rather than redefined per caller: `FrameworkController` and
     * `BarsIndicatorLoader` both pair it with `tryLatestPublished()`, and a
     * caller-local copy is exactly the "same fact, two documents" drift
     * CLAUDE.md warns about elsewhere in this codebase.
     */
    public const NO_PUBLISHED_REVISION = -1;

    /**
     * The project's own pinned revision. `null` (no project context at all)
     * falls back to the latest published revision; an EXISTING project whose
     * pin does not resolve THROWS — see the class docblock.
     */
    public function forProject(?Project $project): int
    {
        if ($project === null) {
            return $this->latestPublished();
        }

        return $this->pinnedRevisionOrThrow($project->frameworkVersion, "project [{$project->id}]");
    }

    /**
     * The FrameworkVersion's own pinned revision. `null` (no version context
     * at all — e.g. an as-yet-unvalidated `framework_version_id` submitted on
     * a request) falls back to the latest published revision; an EXISTING
     * version whose pin does not resolve THROWS — see the class docblock.
     */
    public function forFrameworkVersion(?FrameworkVersion $version): int
    {
        if ($version === null) {
            return $this->latestPublished();
        }

        return $this->pinnedRevisionOrThrow($version, "FrameworkVersion [{$version->id}]");
    }

    private function pinnedRevisionOrThrow(?FrameworkVersion $version, string $subject): int
    {
        if ($version === null || $version->revision_id === null) {
            throw new RuntimeException(
                "CatalogueRevisionResolver: {$subject} has no pinned catalogue revision to resolve. "
                .'Refusing to silently retarget it to whatever revision happens to be latest right now.'
            );
        }

        return $version->revision_id;
    }

    /**
     * Non-throwing counterpart of `forProject()`. `null` (no project context
     * at all) still resolves to the latest published revision. An EXISTING
     * project whose pin does not resolve returns `null` — NEVER a substitute
     * revision, and never a throw: the caller answers its own "not found"
     * outcome (composition_error, an empty catalogue, a stored-text
     * fallback) with it.
     */
    public function tryForProject(?Project $project): ?int
    {
        if ($project === null) {
            return $this->tryLatestPublished();
        }

        return $this->tryPinnedRevision($project->frameworkVersion);
    }

    /**
     * Non-throwing counterpart of `forFrameworkVersion()` — same contract as
     * `tryForProject()`.
     */
    public function tryForFrameworkVersion(?FrameworkVersion $version): ?int
    {
        if ($version === null) {
            return $this->tryLatestPublished();
        }

        return $this->tryPinnedRevision($version);
    }

    private function tryPinnedRevision(?FrameworkVersion $version): ?int
    {
        if ($version === null) {
            return null;
        }

        return $version->revision_id;
    }

    /**
     * The newest PUBLISHED revision — never a draft. Resolved through
     * `FrameworkCatalogRevision::latestPublished()` (framework-catalogue-
     * authoring PR4b, K7) — the ONE implementation `OpenDraftRevision`,
     * `CatalogueExportCommand`, and `FrameworkVersion::
     * assignLatestPublishedRevisionIfUnset()` all resolve through too, so
     * every path in the codebase agrees on "latest" by construction, not by
     * four independently-maintained copies of the same query. Throws when
     * none exists — use `tryLatestPublished()` for a caller that must
     * degrade gracefully instead.
     */
    public function latestPublished(): int
    {
        return $this->tryLatestPublished()
            ?? throw new RuntimeException('No published framework catalogue revision exists.');
    }

    /**
     * Same resolution as `latestPublished()`, returning `null` instead of
     * throwing when no published revision exists at all — for callers that
     * must answer "the catalogue is not loaded yet" as a normal, structured
     * outcome rather than a fatal error.
     */
    public function tryLatestPublished(): ?int
    {
        return FrameworkCatalogRevision::latestPublished()?->id;
    }
}
