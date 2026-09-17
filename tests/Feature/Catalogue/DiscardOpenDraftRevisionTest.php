<?php

declare(strict_types=1);

/**
 * DELETE /api/catalogue/revisions/draft — abandon the currently open catalogue draft
 * (catalogue draft-discard).
 *
 * Before this endpoint existed, `RevisionController` offered `openDraft()`
 * and `publish()` only — publish is irreversible, and the only way to walk
 * back a draft opened by mistake, or one the superadmin simply wants to
 * start over from, was a direct database intervention. This suite proves:
 *   - superadmin-only, matching every other revision-lifecycle action;
 *   - 404 when there is nothing to discard;
 *   - discarding removes the draft AND every row cloned under it —
 *     `framework_roles`, `framework_competencies`, `framework_role_
 *     competency`, `framework_bars_indicators`, `framework_default_
 *     questions` — proving the restrictOnDelete/cascade FK topology this
 *     class's own docblock claims;
 *   - the catalogue reverts to showing the latest published revision,
 *     read-only, exactly the pre-`openDraft()` state;
 *   - the action is audited.
 */

use App\Actions\Catalogue\OpenDraftRevision;
use App\Models\FrameworkCatalogRevision;
use App\Models\User;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\DB;

function dodrSuperadminToken(): string
{
    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);

    return auth('api')->login($user);
}

test('a non-superadmin cannot discard the open draft', function (): void {
    (new FrameworkCatalogSeeder)->run();
    app(OpenDraftRevision::class)->open();

    $user = User::factory()->create();
    $token = auth('api')->login($user);

    test()->withToken($token)->deleteJson('/api/catalogue/revisions/draft')->assertForbidden();
});

test('discarding with no open draft answers 404', function (): void {
    (new FrameworkCatalogSeeder)->run();

    $token = dodrSuperadminToken();

    test()->withToken($token)->deleteJson('/api/catalogue/revisions/draft')->assertNotFound();
});

test('discarding the open draft removes it and every row cloned under it', function (): void {
    (new FrameworkCatalogSeeder)->run();

    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    $draft = app(OpenDraftRevision::class)->open();

    expect($draft->id)->not->toBe($baseline->id);
    expect(DB::table('framework_roles')->where('revision_id', $draft->id)->count())->toBe(5);
    expect(DB::table('framework_competencies')->where('revision_id', $draft->id)->count())->toBe(20);

    $token = dodrSuperadminToken();

    test()->withToken($token)->deleteJson('/api/catalogue/revisions/draft')->assertOk();

    expect(FrameworkCatalogRevision::find($draft->id))->toBeNull();
    expect(DB::table('framework_roles')->where('revision_id', $draft->id)->count())->toBe(0);
    expect(DB::table('framework_competencies')->where('revision_id', $draft->id)->count())->toBe(0);
    expect(DB::table('framework_role_competency')->where('revision_id', $draft->id)->count())->toBe(0);
    expect(DB::table('framework_bars_indicators')->where('revision_id', $draft->id)->count())->toBe(0);

    // The baseline itself, never touched.
    expect(DB::table('framework_roles')->where('revision_id', $baseline->id)->count())->toBe(5);
});

test('discarding reverts the catalogue to the latest published revision, read-only', function (): void {
    (new FrameworkCatalogSeeder)->run();

    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    app(OpenDraftRevision::class)->open();

    $token = dodrSuperadminToken();

    test()->withToken($token)->deleteJson('/api/catalogue/revisions/draft')->assertOk();

    $response = test()->withToken($token)->getJson('/api/catalogue/revisions/current')->assertOk();

    expect($response->json('data.id'))->toBe($baseline->id);
    expect($response->json('data.editable'))->toBeFalse();
});

test('discarding the open draft is audited', function (): void {
    (new FrameworkCatalogSeeder)->run();

    $draft = app(OpenDraftRevision::class)->open();
    $token = dodrSuperadminToken();

    test()->withToken($token)->deleteJson('/api/catalogue/revisions/draft')->assertOk();

    $entry = DB::table('audit_logs')
        ->where('subject_type', 'FrameworkCatalogRevision')
        ->where('subject_id', $draft->id)
        ->where('action', 'revision.draft_discarded')
        ->first();

    expect($entry)->not->toBeNull();
    expect($entry->organization_id)->toBeNull();
    expect($entry->after)->toBeNull();

    $before = json_decode((string) $entry->before, true);
    expect($before['revision_id'])->toBe($draft->id);
});
