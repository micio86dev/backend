<?php

declare(strict_types=1);

/**
 * H7 (framework-catalogue-authoring PR3b, R3-005): the content-immutability
 * trigger originally read only `NEW.revision_id` on UPDATE — it never
 * checked `OLD.revision_id`. A raw UPDATE moving a row's `revision_id` OUT
 * of a published, non-baseline revision INTO some other (e.g. draft)
 * revision passed the ORIGINAL trigger (it only refused where the row was
 * GOING, never where it was COMING FROM) — the exact class of write
 * immutability exists to refuse: a published revision's content must never
 * change, including by relocating it elsewhere.
 */

use App\Models\FrameworkCatalogRevision;
use Illuminate\Support\Facades\DB;

test('an UPDATE cannot move a competency OUT of a published, non-baseline revision', function (): void {
    $published = FrameworkCatalogRevision::factory()->draft()->create();
    $competencyId = DB::table('framework_competencies')->insertGetId([
        'revision_id' => $published->id, 'code' => 'MOVEOUT', 'type' => 'standard',
        'name' => json_encode(['en' => 'x']), 'definition' => json_encode(['en' => 'x']),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('framework_catalog_revisions')->where('id', $published->id)
        ->update(['state' => 'published', 'published_at' => now()]);

    $draft = FrameworkCatalogRevision::factory()->draft()->create();

    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => DB::table('framework_competencies')->where('id', $competencyId)
            ->update(['revision_id' => $draft->id])),
        '23514',
        'framework_catalog_published_content_immutable',
    );

    expect(DB::table('framework_competencies')->where('id', $competencyId)->value('revision_id'))
        ->toBe($published->id);
});

test('an UPDATE MAY still move a row OUT of the baseline (exempt, unchanged behaviour)', function (): void {
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    $competencyId = DB::table('framework_competencies')->insertGetId([
        'revision_id' => $baseline->id, 'code' => 'MOVEBASE', 'type' => 'standard',
        'name' => json_encode(['en' => 'x']), 'definition' => json_encode(['en' => 'x']),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $draft = FrameworkCatalogRevision::factory()->draft()->create();

    DB::table('framework_competencies')->where('id', $competencyId)->update(['revision_id' => $draft->id]);

    expect(DB::table('framework_competencies')->where('id', $competencyId)->value('revision_id'))
        ->toBe($draft->id);
});

test('an UPDATE that keeps a row inside the SAME published, non-baseline revision is still refused', function (): void {
    $published = FrameworkCatalogRevision::factory()->draft()->create();
    $competencyId = DB::table('framework_competencies')->insertGetId([
        'revision_id' => $published->id, 'code' => 'STAYPUB', 'type' => 'standard',
        'name' => json_encode(['en' => 'x']), 'definition' => json_encode(['en' => 'x']),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('framework_catalog_revisions')->where('id', $published->id)
        ->update(['state' => 'published', 'published_at' => now()]);

    assertPostgresConstraintViolation(
        fn () => DB::transaction(fn () => DB::table('framework_competencies')->where('id', $competencyId)
            ->update(['code' => 'CHANGED'])),
        '23514',
        'framework_catalog_published_content_immutable',
    );
});
