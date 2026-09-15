<?php

declare(strict_types=1);

/**
 * RED/GREEN — review-gate fixes on `framework_catalog_revisions` (framework-
 * catalogue-authoring PR 1):
 *
 *  1. The baseline is `published`, never `draft` — proven directly, so the
 *     "it is born draft and nothing ever publishes it" defect cannot regress
 *     silently.
 *  2. `state` has a real CHECK constraint, not just a PHP-side comment.
 *  3. `framework_catalog_revisions_one_draft` actually refuses a second
 *     draft — the partial unique index had zero test coverage before this.
 *  4. A `published` revision is immutable at the model layer: `state`,
 *     `is_baseline` and `published_at` all refuse to change once `state`
 *     was `published`, mirroring `FrameworkVersion`'s own `is_locked` guard.
 */

use App\Exceptions\PublishedRevisionImmutableException;
use App\Models\FrameworkCatalogRevision;
use Illuminate\Support\Facades\DB;

test('the baseline revision is published, not draft', function (): void {
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();

    expect($baseline->state)->toBe('published');
    expect($baseline->published_at)->not->toBeNull();
});

test('the one-draft partial unique index refuses a second draft', function (): void {
    // The baseline is published now (fix above), so the "one draft" slot is
    // free — the FIRST draft in this test is legal.
    FrameworkCatalogRevision::factory()->draft()->create();

    assertPostgresConstraintViolation(
        fn () => DB::table('framework_catalog_revisions')->insert([
            'state' => 'draft',
            'is_baseline' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        sqlstate: '23505', // unique_violation
        constraintName: 'framework_catalog_revisions_one_draft',
    );
});

test('state has a database CHECK constraint, not merely a PHP comment', function (): void {
    assertPostgresConstraintViolation(
        fn () => DB::table('framework_catalog_revisions')->insert([
            'state' => 'Draft', // wrong case — neither 'draft' nor 'published'
            'is_baseline' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        sqlstate: '23514', // check_violation
        constraintName: 'framework_catalog_revisions_state_check',
    );
});

test('a published revision refuses to be mutated back to draft', function (): void {
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();

    expect(fn () => $baseline->update(['state' => 'draft']))
        ->toThrow(PublishedRevisionImmutableException::class);

    expect($baseline->fresh()->state)->toBe('published');
});

test('a published revision refuses any other mutation too', function (): void {
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();

    expect(fn () => $baseline->update(['label' => 'renamed after publish']))
        ->toThrow(PublishedRevisionImmutableException::class);
});

test('a draft revision may still be freely mutated', function (): void {
    $draft = FrameworkCatalogRevision::factory()->draft()->create(['label' => 'before']);

    $draft->update(['label' => 'after']);

    expect($draft->fresh()->label)->toBe('after');
});

test('a published revision cannot be deleted either, not only updated', function (): void {
    // The guard registered `updating` alone while its docblock claimed an exact
    // mirror of `FrameworkVersion::booted()`, which registers `deleting` too.
    // So `$published->delete()` went straight through.
    //
    // The `restrictOnDelete` foreign keys LOOK like they cover this. They do
    // not: they refuse only while content still points at the revision. A
    // revision published before its content was attached — or published and
    // then emptied — had nothing referencing it and was deletable by any
    // Eloquent path. "Immutable, no exceptions" and "deletable while nothing
    // happens to reference it" are not the same sentence, and this test is the
    // difference between them.
    $revision = FrameworkCatalogRevision::factory()->create();

    expect($revision->state)->toBe('published');

    expect(fn () => $revision->delete())
        ->toThrow(PublishedRevisionImmutableException::class);

    expect(FrameworkCatalogRevision::query()->whereKey($revision->getKey())->exists())->toBeTrue();
});
