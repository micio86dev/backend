<?php

declare(strict_types=1);

/**
 * Z13 (R2-misleading-exception-name, REQUIRED BEFORE ARCHIVE):
 * `RevisionPublishedDuringWriteException`'s own docblock already discloses
 * two DISTINCT causes a locked write can find once it unblocks — the row
 * still exists but is no longer `draft` (a concurrent PUBLISH), or the row
 * does not exist at all (a concurrent DISCARD) — but the error code used to
 * name only the first. `withRevisionLockedForWrite()` now distinguishes
 * them at the throw site.
 *
 * Both branches are pure, deterministic facts about ROW STATE
 * (`$revision === null` vs `$revision->state !== 'draft'`), not about
 * timing — reproduced directly rather than through a genuine two-process
 * race (already proven separately, `DiscardRaceWithConcurrentWriteTest`'s
 * K8 test, for the PUBLISHED cause specifically).
 */

use App\Enums\RevisionWriteConflictCause;
use App\Exceptions\RevisionPublishedDuringWriteException;
use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;

test('Z13: a revision that no longer exists throws with the Discarded cause', function (): void {
    $nonExistentRevisionId = 999999999;

    try {
        Competency::withRevisionLockedForWrite($nonExistentRevisionId, fn () => null);
        $this->fail('expected RevisionPublishedDuringWriteException');
    } catch (RevisionPublishedDuringWriteException $e) {
        expect($e->cause())->toBe(RevisionWriteConflictCause::Discarded);
        expect($e->errorCode())->toBe('revision_discarded_during_write');
    }
});

test('Z13: a revision that still exists but is no longer draft throws with the Published cause', function (): void {
    $publishedRevisionId = FrameworkCatalogRevision::factory()->create(['state' => 'published'])->id;

    try {
        Competency::withRevisionLockedForWrite($publishedRevisionId, fn () => null);
        $this->fail('expected RevisionPublishedDuringWriteException');
    } catch (RevisionPublishedDuringWriteException $e) {
        expect($e->cause())->toBe(RevisionWriteConflictCause::Published);
        expect($e->errorCode())->toBe('revision_published_during_write');
    }
});
