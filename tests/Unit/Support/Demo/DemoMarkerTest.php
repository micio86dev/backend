<?php

declare(strict_types=1);

/**
 * DemoMarker — the reserved-prefix invariant demo data is built on (D1).
 *
 * `beai:demo-seed` shares an organization with real client data, so every row
 * it creates directly must carry a stable, visible marker: a reserved prefix
 * on the one natural free-text key each marked root already has
 * (`projects.slug`, `participants.candidate_ref`, `framework_versions.version`,
 * `avatar_templates.name`). Everything downstream of a marked root is reached
 * by cascade, never marked itself.
 *
 * This class is the single source of truth for the prefix and its predicates —
 * both the seed writer and the teardown selector import it, so "what counts as
 * demo data" can never drift between the two commands.
 */

use App\Support\Demo\DemoMarker;

test('the reserved prefix is beai-demo-', function (): void {
    expect(DemoMarker::PREFIX)->toBe('beai-demo-');
});

test('isDemoSlug recognizes a value carrying the prefix', function (): void {
    expect(DemoMarker::isDemoSlug('beai-demo-sales-ico'))->toBeTrue();
    expect(DemoMarker::isDemoSlug('client-sales-ico'))->toBeFalse();
});

test('isDemoSlug is false for null', function (): void {
    expect(DemoMarker::isDemoSlug(null))->toBeFalse();
});

test('isDemoRef recognizes a value carrying the prefix', function (): void {
    expect(DemoMarker::isDemoRef('beai-demo-c-001'))->toBeTrue();
    expect(DemoMarker::isDemoRef('real-candidate-ref'))->toBeFalse();
});

test('isDemoRef is false for null', function (): void {
    expect(DemoMarker::isDemoRef(null))->toBeFalse();
});

test('matches is the shared predicate both isDemoSlug and isDemoRef delegate to', function (): void {
    // framework_versions.version and avatar_templates.name have no dedicated
    // predicate name in the spec, but they are marked with the same prefix —
    // this is the generic check the writer/teardown use for those two fields.
    expect(DemoMarker::matches('beai-demo-1.0.0'))->toBeTrue();
    expect(DemoMarker::matches('beai-demo-heygen-it'))->toBeTrue();
    expect(DemoMarker::matches('1.0.0'))->toBeFalse();
});

test('a value that merely contains the prefix mid-string is not marked', function (): void {
    // The marker is a PREFIX, not a substring — "not-beai-demo-anything" was
    // never written by beai:demo-seed and must not be swept by teardown.
    expect(DemoMarker::matches('not-beai-demo-anything'))->toBeFalse();
});
