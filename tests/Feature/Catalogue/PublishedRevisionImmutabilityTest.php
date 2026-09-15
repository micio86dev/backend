<?php

declare(strict_types=1);

/**
 * RED/GREEN — 12.3 (framework-catalogue-authoring PR3): a published
 * revision refuses update, delete, AND insert — three assertions, stricter
 * than the old seeder guard.
 *
 * Two layers, each proven independently:
 *   1. The CRUD API surface never reaches a published revision at all —
 *      every write is scoped to `Model::where('revision_id', $openDraftId)
 *      ->findOrFail(...)`, so an id belonging to a published revision 404s
 *      rather than writing.
 *   2. The DB-level trigger (`2026_09_15_201434_enforce_catalogue_published_
 *      content_immutability`) refuses even a raw, Eloquent-bypassing write
 *      naming a published, non-baseline revision directly — the backstop
 *      for a bug that skips the CRUD surface entirely.
 */

use App\Models\FrameworkCatalogRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('the CRUD surface 404s rather than writing to a published, non-baseline revision', function (): void {
    // A real published revision with content — created draft, populated,
    // then flipped (mirrors PublishRevision's own end state).
    $published = FrameworkCatalogRevision::factory()->draft()->create();
    $roleId = DB::table('framework_roles')->insertGetId([
        'revision_id' => $published->id, 'code' => 'PUBIMMUT', 'name' => json_encode(['en' => 'x']),
        'responsibilities' => json_encode(['en' => 'x']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('framework_catalog_revisions')->where('id', $published->id)
        ->update(['state' => 'published', 'published_at' => now()]);

    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
    $token = auth('api')->login($user);

    // No open draft exists yet — the CRUD surface auto-opens ITS OWN new
    // draft (cloned from the latest published revision) rather than ever
    // touching $published directly, so the id it just tried to reach
    // belongs to a DIFFERENT revision than whatever the request resolves —
    // 404, never a write.
    $this->withToken($token)
        ->patchJson("/api/catalogue/roles/{$roleId}", ['name' => ['en' => 'renamed']])
        ->assertStatus(404);

    $this->withToken($token)
        ->deleteJson("/api/catalogue/roles/{$roleId}")
        ->assertStatus(404);

    expect(DB::table('framework_roles')->where('id', $roleId)->where('name->en', 'x')->exists())->toBeTrue();
});

test('the DB trigger refuses INSERT into a published, non-baseline revision directly', function (): void {
    $published = FrameworkCatalogRevision::factory()->draft()->create();
    DB::table('framework_competencies')->insert([
        'revision_id' => $published->id, 'code' => 'SEED', 'type' => 'standard',
        'name' => json_encode(['en' => 'x']), 'definition' => json_encode(['en' => 'x']),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('framework_catalog_revisions')->where('id', $published->id)
        ->update(['state' => 'published', 'published_at' => now()]);

    assertPostgresConstraintViolation(
        fn () => DB::table('framework_competencies')->insert([
            'revision_id' => $published->id, 'code' => 'NEWONE', 'type' => 'standard',
            'name' => json_encode(['en' => 'y']), 'definition' => json_encode(['en' => 'y']),
            'created_at' => now(), 'updated_at' => now(),
        ]),
        '23514',
        'framework_catalog_published_content_immutable',
    );
});

test('the DB trigger refuses UPDATE on a published, non-baseline revision directly', function (): void {
    $published = FrameworkCatalogRevision::factory()->draft()->create();
    $competencyId = DB::table('framework_competencies')->insertGetId([
        'revision_id' => $published->id, 'code' => 'SEED2', 'type' => 'standard',
        'name' => json_encode(['en' => 'x']), 'definition' => json_encode(['en' => 'x']),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('framework_catalog_revisions')->where('id', $published->id)
        ->update(['state' => 'published', 'published_at' => now()]);

    assertPostgresConstraintViolation(
        fn () => DB::table('framework_competencies')->where('id', $competencyId)->update(['code' => 'CHANGED']),
        '23514',
        'framework_catalog_published_content_immutable',
    );
});

test('the DB trigger refuses DELETE on a published, non-baseline revision directly', function (): void {
    $published = FrameworkCatalogRevision::factory()->draft()->create();
    $competencyId = DB::table('framework_competencies')->insertGetId([
        'revision_id' => $published->id, 'code' => 'SEED3', 'type' => 'standard',
        'name' => json_encode(['en' => 'x']), 'definition' => json_encode(['en' => 'x']),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('framework_catalog_revisions')->where('id', $published->id)
        ->update(['state' => 'published', 'published_at' => now()]);

    assertPostgresConstraintViolation(
        fn () => DB::table('framework_competencies')->where('id', $competencyId)->delete(),
        '23514',
        'framework_catalog_published_content_immutable',
    );
});
