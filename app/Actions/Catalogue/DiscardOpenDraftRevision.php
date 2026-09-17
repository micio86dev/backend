<?php

declare(strict_types=1);

namespace App\Actions\Catalogue;

use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\Role;
use App\Models\User;
use App\Support\Superadmin\PlatformAuditWriter;

/**
 * Discard the currently OPEN draft revision, abandoning every uncommitted
 * change it holds (catalogue draft-discard).
 *
 * Not `DiscardUnusedDraftRevision`: that class discards a draft only when a
 * SAME-request validation failure proves it was cloned and never touched
 * (`content_version === 0`), and never throws — it is a silent cleanup, not
 * an operator-facing action. This class is the opposite on purpose: it is
 * the only way a superadmin can abandon a draft that HAS been worked on,
 * because the only other exit `RevisionController` offered was `publish()`
 * — irreversible, and the wrong tool for "I opened this by mistake" or "I
 * want to start over".
 *
 * The caller resolves `$draft` from `FrameworkCatalogRevision::openDraft()`
 * — never from a request-supplied id — exactly like `PublishRevision::
 * publish()` receives its own `$draft` parameter, so this class can never be
 * pointed at a published revision.
 *
 * Deletion order mirrors `DiscardUnusedDraftRevision`'s own reasoning
 * exactly: only `framework_competencies` and `framework_roles` are deleted
 * explicitly. `framework_bars_indicators`, `framework_role_competency` and
 * `framework_default_questions` all cascade off composite FKs into one or
 * both of those two tables (see `2026_09_15_090001_add_revision_to_
 * framework_catalog.php` and `2026_09_15_090002_create_framework_default_
 * questions_table.php`) — deleting the two parents first is what lets the
 * revision's own `restrictOnDelete()` FKs from all five tables be satisfied
 * by the time `$draft->delete()` runs.
 *
 * Locked exactly like the catalogue's own CRUD surface
 * (`BumpsRevisionContentVersion::withRevisionLockedForWrite()`): a
 * concurrent write racing this discard either completes first inside this
 * same lock (and is then discarded along with everything else, which is
 * correct — the operator asked to abandon the WHOLE draft) or is refused
 * with the same `409 revision_conflict` every other catalogue write already
 * answers with once this transaction holds the lock first.
 *
 * `FrameworkVersion` can never reference a draft (`FrameworkVersion::
 * refuseDraftRevisionTarget()` refuses that pin outright), so deleting a
 * draft revision can never orphan a project's own pin.
 */
final class DiscardOpenDraftRevision
{
    public function __construct(
        private readonly PlatformAuditWriter $auditWriter,
    ) {}

    public function discard(FrameworkCatalogRevision $draft, User $actor): void
    {
        $before = [
            'revision_id' => $draft->id,
            'parent_revision_id' => $draft->parent_revision_id,
            'label' => $draft->label,
        ];

        Competency::withRevisionLockedForWrite($draft->id, function () use ($draft, $actor, $before): void {
            Competency::where('revision_id', $draft->id)->delete();
            Role::where('revision_id', $draft->id)->delete();

            $draft->delete();

            $this->auditWriter->record(
                actorId: $actor->id,
                action: 'revision.draft_discarded',
                subjectType: 'FrameworkCatalogRevision',
                subjectId: $draft->id,
                before: $before,
                after: null,
            );
        });
    }
}
