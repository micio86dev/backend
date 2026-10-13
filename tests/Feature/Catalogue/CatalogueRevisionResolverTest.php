<?php

declare(strict_types=1);

/**
 * `CatalogueRevisionResolver` — one rule: an entity that is already pinned never
 * silently falls back to "whatever is latest now". No entity at all resolves to
 * the latest PUBLISHED revision (never a draft). The `for*` family throws on an
 * unresolvable pin; the `try*` family degrades to null.
 */

use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkVersion;
use App\Models\Project;
use App\Support\Catalogue\CatalogueRevisionResolver;
use Illuminate\Support\Facades\DB;

function unpinnedFrameworkVersion(): FrameworkVersion
{
    $version = new FrameworkVersion;
    $version->id = 987;
    $version->revision_id = null;

    return $version;
}

function projectPinnedTo(?FrameworkVersion $version): Project
{
    $project = new Project;
    $project->id = 555;
    $project->setRelation('frameworkVersion', $version);

    return $project;
}

beforeEach(function (): void {
    $this->resolver = new CatalogueRevisionResolver;
});

test('no context resolves to the latest published revision, ignoring a newer draft', function (): void {
    $published = FrameworkCatalogRevision::factory()->create(['published_at' => now()->addMinute()]);
    FrameworkCatalogRevision::factory()->draft()->create();

    expect($this->resolver->forProject(null))->toBe($published->id)
        ->and($this->resolver->forFrameworkVersion(null))->toBe($published->id)
        ->and($this->resolver->tryForProject(null))->toBe($published->id)
        ->and($this->resolver->tryForFrameworkVersion(null))->toBe($published->id)
        ->and($this->resolver->latestPublished())->toBe($published->id);
});

test('a pinned entity resolves to its own revision even when a newer one is published', function (): void {
    $pinned = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    FrameworkCatalogRevision::factory()->create(['published_at' => now()->addMinute()]);
    $version = new FrameworkVersion;
    $version->revision_id = $pinned->id;

    expect($this->resolver->forFrameworkVersion($version))->toBe($pinned->id)
        ->and($this->resolver->forProject(projectPinnedTo($version)))->toBe($pinned->id)
        ->and($this->resolver->tryForFrameworkVersion($version))->toBe($pinned->id)
        ->and($this->resolver->tryForProject(projectPinnedTo($version)))->toBe($pinned->id);
});

test('an existing but unpinned version throws instead of retargeting to the latest revision', function (): void {
    expect(fn () => $this->resolver->forFrameworkVersion(unpinnedFrameworkVersion()))
        ->toThrow(RuntimeException::class, 'FrameworkVersion [987] has no pinned catalogue revision');

    expect(fn () => $this->resolver->forProject(projectPinnedTo(unpinnedFrameworkVersion())))
        ->toThrow(RuntimeException::class, 'project [555] has no pinned catalogue revision');
});

test('a project with no framework version at all also throws rather than substituting', function (): void {
    expect(fn () => $this->resolver->forProject(projectPinnedTo(null)))
        ->toThrow(RuntimeException::class, 'project [555]');
});

test('the try family answers null for an unresolvable pin, never a substitute revision', function (): void {
    expect($this->resolver->tryForFrameworkVersion(unpinnedFrameworkVersion()))->toBeNull()
        ->and($this->resolver->tryForProject(projectPinnedTo(unpinnedFrameworkVersion())))->toBeNull()
        ->and($this->resolver->tryForProject(projectPinnedTo(null)))->toBeNull();
});

test('on an unseeded platform latestPublished throws and tryLatestPublished is null', function (): void {
    DB::table('framework_catalog_revisions')->delete();

    expect($this->resolver->tryLatestPublished())->toBeNull()
        ->and($this->resolver->tryForProject(null))->toBeNull()
        ->and(fn () => $this->resolver->latestPublished())->toThrow(RuntimeException::class, 'No published framework catalogue revision exists.');
});

test('viewable() prefers the open draft and falls back to the latest published revision', function (): void {
    $latest = FrameworkCatalogRevision::latestPublished();

    expect(FrameworkCatalogRevision::viewable()->is($latest))->toBeTrue();

    $draft = FrameworkCatalogRevision::factory()->draft()->create();

    expect(FrameworkCatalogRevision::viewable()->is($draft))->toBeTrue()
        ->and(FrameworkCatalogRevision::openDraft()->is($draft))->toBeTrue();
});
