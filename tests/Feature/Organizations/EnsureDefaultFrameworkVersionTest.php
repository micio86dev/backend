<?php

declare(strict_types=1);

/**
 * `EnsureDefaultFrameworkVersion` — every organization must own at least one
 * FrameworkVersion, because `POST /api/projects` only accepts a
 * `framework_version_id` of the caller's OWN organization and nothing else
 * ever created one (owner report: a new client could not create a project).
 */

use App\Actions\Organizations\EnsureDefaultFrameworkVersion;
use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkVersion;
use App\Models\Organization;
use App\Support\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function versionsOf(Organization $org): Collection
{
    return FrameworkVersion::withoutGlobalScopes()->where('organization_id', $org->id)->get();
}

test('creates exactly one unlocked default version pinned to the latest published revision', function (): void {
    $org = Organization::factory()->create();
    $latest = FrameworkCatalogRevision::latestPublished();
    expect($latest)->not->toBeNull();

    $created = app(EnsureDefaultFrameworkVersion::class)->ensure($org);

    expect($created)->toBeTrue();
    $versions = versionsOf($org);
    expect($versions)->toHaveCount(1);
    $fv = $versions->first();
    expect($fv->version)->toBe('1.0.0')
        ->and($fv->label)->toBe('Default framework version')
        ->and($fv->is_locked)->toBeFalse()
        ->and($fv->organization_id)->toBe($org->id)
        ->and($fv->revision_id)->toBe($latest->id)
        ->and($fv->revision->state)->toBe('published');
});

test('pins to the latest published revision, never to a newer draft', function (): void {
    FrameworkCatalogRevision::factory()->draft()->create();
    $latest = FrameworkCatalogRevision::latestPublished();
    $org = Organization::factory()->create();

    app(EnsureDefaultFrameworkVersion::class)->ensure($org);

    expect(versionsOf($org)->first()->revision_id)->toBe($latest->id);
});

test('does nothing when the organization already has a version, even a locked one', function (): void {
    $org = Organization::factory()->create();
    $existing = TenantContextScope::runFor($org->id, fn () => FrameworkVersion::factory()->create(['organization_id' => $org->id]));
    DB::table('framework_versions')->where('id', $existing->id)->update(['is_locked' => true]);

    $created = app(EnsureDefaultFrameworkVersion::class)->ensure($org);

    expect($created)->toBeFalse();
    expect(versionsOf($org))->toHaveCount(1);
    expect(versionsOf($org)->first()->id)->toBe($existing->id);
});

test('is idempotent: a second call adds nothing', function (): void {
    $org = Organization::factory()->create();
    $action = app(EnsureDefaultFrameworkVersion::class);

    expect($action->ensure($org))->toBeTrue();
    expect($action->ensure($org))->toBeFalse();
    expect(versionsOf($org))->toHaveCount(1);
});

test('reports false and creates nothing when no revision is published yet', function (): void {
    $org = Organization::factory()->create();
    DB::statement('ALTER TABLE framework_catalog_revisions DISABLE TRIGGER USER');
    DB::table('framework_catalog_revisions')->update(['state' => 'draft']);
    DB::statement('ALTER TABLE framework_catalog_revisions ENABLE TRIGGER USER');
    expect(FrameworkCatalogRevision::latestPublished())->toBeNull();

    $created = app(EnsureDefaultFrameworkVersion::class)->ensure($org);

    expect($created)->toBeFalse();
    expect(versionsOf($org))->toHaveCount(0);
});

test('only the target organization gains a version', function (): void {
    $target = Organization::factory()->create();
    $other = Organization::factory()->create();
    $withVersion = Organization::factory()->create();
    TenantContextScope::runFor($withVersion->id, fn () => FrameworkVersion::factory()->create(['organization_id' => $withVersion->id]));

    app(EnsureDefaultFrameworkVersion::class)->ensure($target);

    expect(versionsOf($target))->toHaveCount(1);
    expect(versionsOf($other))->toHaveCount(0);
    expect(versionsOf($withVersion))->toHaveCount(1);
});
