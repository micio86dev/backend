<?php

declare(strict_types=1);

/**
 * RED/GREEN — 4.4 (framework-catalogue-authoring PR 1): creating/resolving a
 * `FrameworkVersion` against a `draft` revision is rejected. See
 * catalogue-authoring spec — "A FrameworkVersion MUST NOT be creatable or
 * resolvable against a draft revision — only a published revision may be
 * pinned."
 */

use App\Exceptions\DraftRevisionPinRejectedException;
use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Support\Tenancy\TenantResolver;

beforeEach(function (): void {
    $org = Organization::factory()->create();
    $this->org = $org;
    app(TenantResolver::class)->setOrgId($org->id);
});

test('creating a FrameworkVersion pinned to a draft revision is rejected', function (): void {
    // The baseline is `published` (review-gate fix) — a draft must be
    // created explicitly to exercise this path at all.
    $draftRevisionId = FrameworkCatalogRevision::factory()->draft()->create()->id;

    expect(fn () => FrameworkVersion::create([
        'organization_id' => $this->org->id,
        'version' => 'v-draft-pin',
        'revision_id' => $draftRevisionId,
    ]))->toThrow(DraftRevisionPinRejectedException::class);

    expect(FrameworkVersion::where('version', 'v-draft-pin')->exists())->toBeFalse();
});

test('updating an existing FrameworkVersion to pin a draft revision is rejected', function (): void {
    $draftRevisionId = FrameworkCatalogRevision::factory()->draft()->create()->id;

    $fv = FrameworkVersion::create([
        'organization_id' => $this->org->id,
        'version' => 'v-update-target',
    ]);

    expect(fn () => tap($fv)->update(['revision_id' => $draftRevisionId]))
        ->toThrow(DraftRevisionPinRejectedException::class);
});

test('creating a FrameworkVersion pinned to a published revision succeeds', function (): void {
    // The baseline is published by construction (review-gate fix) — no need
    // to hand-roll a second published revision just to exercise this path.
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();

    $fv = FrameworkVersion::create([
        'organization_id' => $this->org->id,
        'version' => 'v-published-pin',
        'revision_id' => $baseline->id,
    ]);

    expect($fv->revision_id)->toBe($baseline->id);
    expect($fv->revision->state)->toBe('published');
});

test('a FrameworkVersion created without naming a revision at all is unaffected by the guard', function (): void {
    $fv = FrameworkVersion::create([
        'organization_id' => $this->org->id,
        'version' => 'v-no-revision',
    ]);

    expect($fv->revision_id)->toBeNull();
});
