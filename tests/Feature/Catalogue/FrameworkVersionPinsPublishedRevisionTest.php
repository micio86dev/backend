<?php

declare(strict_types=1);

/**
 * RED/GREEN — G1/G2 ("GAP FOUND DURING PR 1", tasks.md, closed in PR3): a
 * newly created `FrameworkVersion` resolves the latest PUBLISHED revision,
 * never null and never a draft; an existing version keeps the revision it
 * was stamped with.
 *
 * See `FrameworkVersion::assignLatestPublishedRevisionIfUnset()` for the
 * decision this test proves: the column stays NULLABLE (G2's "decide,
 * stated not assumed"), enforced at the application layer instead.
 */

use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Support\Tenancy\TenantResolver;

beforeEach(function (): void {
    $org = Organization::factory()->create();
    $this->org = $org;
    app(TenantResolver::class)->setOrgId($org->id);
});

test('a new FrameworkVersion with no explicit revision resolves the latest published revision', function (): void {
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();

    $fv = FrameworkVersion::create([
        'organization_id' => $this->org->id,
        'version' => 'g1-auto-pin',
    ]);

    expect($fv->revision_id)->not->toBeNull();
    expect($fv->revision_id)->toBe($baseline->id);
    expect($fv->revision->state)->toBe('published');
});

test('a new FrameworkVersion never resolves to a draft revision', function (): void {
    // A LATER-published, non-baseline revision must be preferred over the
    // baseline once one exists — "the latest published revision", not "the
    // baseline specifically".
    $newer = FrameworkCatalogRevision::factory()->create(['published_at' => now()->addMinute()]);

    // An unrelated open draft must never be picked, even though it is
    // technically "newer" by id than $newer.
    FrameworkCatalogRevision::factory()->draft()->create();

    $fv = FrameworkVersion::create([
        'organization_id' => $this->org->id,
        'version' => 'g1-prefers-latest-published',
    ]);

    expect($fv->revision_id)->toBe($newer->id);
    expect($fv->revision->state)->toBe('published');
});

test('an existing FrameworkVersion keeps the revision it was stamped with', function (): void {
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();

    $fv = FrameworkVersion::create([
        'organization_id' => $this->org->id,
        'version' => 'g1-existing',
        'revision_id' => $baseline->id,
    ]);

    $newer = FrameworkCatalogRevision::factory()->create(['published_at' => now()->addMinute()]);

    expect($fv->fresh()->revision_id)->toBe($baseline->id);
    expect($fv->fresh()->revision_id)->not->toBe($newer->id);
});

test('an explicitly-null revision assignment is still auto-resolved on create, matching "unset" — only isDirty callers pass null through untouched', function (): void {
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();

    $fv = FrameworkVersion::create([
        'organization_id' => $this->org->id,
        'version' => 'g1-null-still-resolves',
        'revision_id' => null,
    ]);

    expect($fv->revision_id)->toBe($baseline->id);
});
