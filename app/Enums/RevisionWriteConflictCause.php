<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Z13 (R2-misleading-exception-name, framework-catalogue-authoring, REQUIRED
 * BEFORE ARCHIVE): the two DISTINCT reasons
 * `BumpsRevisionContentVersion::withRevisionLockedForWrite()` finds the
 * locked revision no longer usable once it unblocks —
 * `RevisionPublishedDuringWriteException`'s own docblock already discloses
 * both, but its error code used to name only one.
 *
 *   Published  — the row still exists, but a concurrent `PublishRevision::
 *                 publish()` flipped its `state` away from `draft`.
 *   Discarded  — the row no longer exists at all: a concurrent
 *                 `DiscardUnusedDraftRevision::discard()` deleted it.
 */
enum RevisionWriteConflictCause
{
    case Published;
    case Discarded;
}
