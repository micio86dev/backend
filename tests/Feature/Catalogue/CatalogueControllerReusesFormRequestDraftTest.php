<?php

declare(strict_types=1);

/**
 * H5 (framework-catalogue-authoring PR3b, R3-002): every catalogue `store()`
 * action must reuse the draft id its OWN FormRequest already resolved via
 * `ResolvesOpenDraftRevision::openDraftRevisionId()`, never call
 * `OpenDraftRevision::open()` a second, independent time. A second call is
 * not merely wasteful: a publish landing in the gap between the
 * FormRequest's `rules()` running and the controller body executing would
 * make that second call see no open draft, clone a BRAND NEW one, and
 * insert against a revision the `rules()` `exists`/uniqueness checks never
 * validated against.
 *
 * Proven by counting the exact "does an open draft already exist" query
 * (`OpenDraftRevision::open()`'s own first statement) fired during ONE
 * `store()` request — the FormRequest's cached `openDraftRevisionId()`
 * issues it exactly once; a second, independent `OpenDraftRevision::open()`
 * call (the pre-fix shape) would issue it again.
 */

use App\Actions\Catalogue\DiscardUnusedDraftRevision;
use App\Actions\Catalogue\OpenDraftRevision;
use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

function h5CountOpenDraftExistenceQueries(callable $request): int
{
    $count = 0;

    DB::listen(function ($query) use (&$count): void {
        if (str_contains($query->sql, 'framework_catalog_revisions')
            && str_contains($query->sql, '"state" = ?')
            && ! str_contains($query->sql, 'order by')
            && str_starts_with($query->sql, 'select')
        ) {
            $count++;
        }
    });

    $request();

    return $count;
}

test('RoleController::store() reuses the FormRequest draft id, never re-opening', function (): void {
    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
    $token = auth('api')->login($user);

    $count = h5CountOpenDraftExistenceQueries(function () use ($token): void {
        $this->withToken($token)->postJson('/api/catalogue/roles', [
            'code' => 'H5ROLE',
            'name' => ['en' => 'x', 'it' => 'x'],
        ])->assertStatus(201);
    });

    expect($count)->toBe(1);
});

test('a store() request that fails validation discards the draft it created, never leaving an orphan', function (): void {
    // gga review finding on H5 (blocking): openDraftRevisionId() runs
    // inside rules(), BEFORE a single rule is evaluated — a 422 for a
    // genuinely malformed payload must not still leave a brand-new,
    // ~450-row draft sitting in the platform-wide one-draft slot with
    // nobody aware it exists.
    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
    $token = auth('api')->login($user);

    expect(FrameworkCatalogRevision::openDraft())->toBeNull();
    $countBefore = FrameworkCatalogRevision::count();

    // `code` omitted entirely — fails StoreRoleRequest's `required` rule.
    $this->withToken($token)->postJson('/api/catalogue/roles', [
        'name' => ['en' => 'x', 'it' => 'x'],
    ])->assertStatus(422);

    expect(FrameworkCatalogRevision::openDraft())->toBeNull();
    // Exactly the pre-request count (e.g. the baseline itself, whatever it
    // may be) — not merely "no draft", but genuinely no NEW row survived.
    expect(FrameworkCatalogRevision::count())->toBe($countBefore);
});

test('a store() request that fails validation against an ALREADY-open draft leaves it untouched', function (): void {
    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
    $token = auth('api')->login($user);

    // Opens the platform's one draft for real, with real content.
    $this->withToken($token)->postJson('/api/catalogue/roles', [
        'code' => 'PRIORROLE',
        'name' => ['en' => 'x', 'it' => 'x'],
    ])->assertStatus(201);

    $draft = FrameworkCatalogRevision::openDraft();
    expect($draft)->not->toBeNull();

    // A second, unrelated request against the SAME already-open draft fails
    // validation (missing `code`) — this request never created the draft,
    // so it must never discard someone else's real work.
    $this->withToken($token)->postJson('/api/catalogue/roles', [
        'name' => ['en' => 'y', 'it' => 'y'],
    ])->assertStatus(422);

    expect(FrameworkCatalogRevision::openDraft()?->id)->toBe($draft->id);
    expect(DB::table('framework_roles')->where('revision_id', $draft->id)->where('code', 'PRIORROLE')->exists())->toBeTrue();
});

test('DiscardUnusedDraftRevision refuses a draft that already carries a NEW row from a concurrent request', function (): void {
    // gga review finding (blocking, second pass): "I created this row"
    // (wasRecentlyCreated) is not the same question as "is anyone else
    // using it right now" — the one-draft unique index means a SECOND,
    // concurrent request can legitimately continue the SAME draft a first
    // request created, before that first request's own (unrelated)
    // validation fails. Simulated directly at the action level: a fresh
    // draft (an exact, untouched clone) gains ONE extra row through the
    // SAME Eloquent path a real controller write uses — exactly what a
    // concurrent request's own successful write would do — and the action
    // must then refuse to discard it.
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    $draft = app(OpenDraftRevision::class)->open();

    Competency::create(['revision_id' => $draft->id, 'code' => 'CONCURRENTB', 'type' => 'standard', 'name' => ['en' => 'x'], 'definition' => ['en' => 'x']]);

    app(DiscardUnusedDraftRevision::class)->discard($draft->id);

    expect(FrameworkCatalogRevision::find($draft->id))->not->toBeNull();
    expect(DB::table('framework_competencies')->where('revision_id', $draft->id)->where('code', 'CONCURRENTB')->exists())->toBeTrue();
    expect($baseline->exists)->toBeTrue();
});

test('DiscardUnusedDraftRevision refuses a draft a concurrent request only RENAMED — the mutation row counts cannot see', function (): void {
    // gga review finding (blocking, THIRD pass): row counts are invariant
    // under UPDATE. A concurrent request that renames an existing cloned
    // role changes nothing a per-table count comparison can detect — this
    // is the exact mutation the count-based version of this guard could
    // not catch. `content_version` (bumped by `BumpsRevisionContentVersion`
    // on every Eloquent write, update included) closes it.
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    Role::create(['revision_id' => $baseline->id, 'code' => 'RENAMESRC', 'name' => ['en' => 'x'], 'responsibilities' => ['en' => 'x']]);

    $draft = app(OpenDraftRevision::class)->open();
    $role = Role::where('revision_id', $draft->id)->where('code', 'RENAMESRC')->firstOrFail();

    $role->update(['name' => ['en' => 'Renamed by a concurrent request']]);

    app(DiscardUnusedDraftRevision::class)->discard($draft->id);

    expect(FrameworkCatalogRevision::find($draft->id))->not->toBeNull();
    expect(Role::find($role->id)->getTranslation('name', 'en'))->toBe('Renamed by a concurrent request');
});

test('DiscardUnusedDraftRevision discards a genuinely untouched clone', function (): void {
    $draft = app(OpenDraftRevision::class)->open();

    app(DiscardUnusedDraftRevision::class)->discard($draft->id);

    expect(FrameworkCatalogRevision::find($draft->id))->toBeNull();
    expect(DB::table('framework_roles')->where('revision_id', $draft->id)->exists())->toBeFalse();
});

test('a discard failure is logged and swallowed, never converting a clean outcome into an uncaught exception', function (): void {
    // gga review finding (blocking): the class's own docblock claims
    // "never throws ... logged, not silent" but nothing forced the
    // try/catch to actually run before this test — deleting it left every
    // other test in this file green. Forced here via a REAL constraint
    // violation: a live project_competencies row referencing this exact
    // competency (restrictOnDelete against framework_competencies) makes
    // discard()'s own Competency::delete() call genuinely fail.
    Log::spy();

    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    Competency::create(['revision_id' => $baseline->id, 'code' => 'FORCEDFAIL', 'type' => 'standard', 'name' => ['en' => 'x'], 'definition' => ['en' => 'x']]);

    $draft = app(OpenDraftRevision::class)->open();
    $competencyId = DB::table('framework_competencies')->where('revision_id', $draft->id)->where('code', 'FORCEDFAIL')->value('id');

    $org = Organization::factory()->create();
    $resolver = app(TenantResolver::class);
    $resolver->setOrgId($org->id);
    $resolver->setBypass(false);

    $project = Project::factory()->create(['organization_id' => $org->id]);
    DB::table('project_competencies')->insert([
        'project_id' => $project->id,
        'competency_id' => $competencyId,
        'position' => 0,
    ]);

    app(DiscardUnusedDraftRevision::class)->discard($draft->id);

    // The transaction rolled back rather than half-completing — the draft
    // and its content survive exactly as they were.
    expect(FrameworkCatalogRevision::find($draft->id))->not->toBeNull();
    expect(DB::table('framework_competencies')->where('id', $competencyId)->exists())->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['revision_id'] === $draft->id);
});

test('BumpsRevisionContentVersion never bumps a PUBLISHED revision, keeping the immutability invariant absolute', function (): void {
    // gga review finding (blocking): a raw DB::table()->increment() fires
    // no Eloquent event, so without the `where('state', 'draft')` filter it
    // would silently UPDATE a published row behind FrameworkCatalogRevision
    // ::booted()'s own immutability guard's back — the baseline is
    // published from creation and deliberately exempt from the
    // content-immutability trigger, so this is reachable through the
    // suite's own ordinary "create against the baseline" call sites.
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    expect($baseline->content_version)->toBe(0);

    Role::create(['revision_id' => $baseline->id, 'code' => 'PUBLISHEDWRITE', 'name' => ['en' => 'x'], 'responsibilities' => ['en' => 'x']]);

    expect($baseline->fresh()->content_version)->toBe(0);
});

test('CompetencyController::store() reuses the FormRequest draft id, never re-opening', function (): void {
    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
    $token = auth('api')->login($user);

    $count = h5CountOpenDraftExistenceQueries(function () use ($token): void {
        $this->withToken($token)->postJson('/api/catalogue/competencies', [
            'code' => 'H5COMP',
            'type' => 'standard',
            'name' => ['en' => 'x', 'it' => 'x'],
            'definition' => ['en' => 'x', 'it' => 'x'],
        ])->assertStatus(201);
    });

    expect($count)->toBe(1);
});

test('BarsIndicatorController::store() reuses the FormRequest draft id, never re-opening', function (): void {
    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
    $token = auth('api')->login($user);

    // A draft must already exist with a competency to attach the indicator
    // to — opened via the role/competency creation above in a real caller,
    // but this test only needs ONE open draft with one competency present.
    $this->withToken($token)->postJson('/api/catalogue/competencies', [
        'code' => 'H5COMP2',
        'type' => 'standard',
        'name' => ['en' => 'x', 'it' => 'x'],
        'definition' => ['en' => 'x', 'it' => 'x'],
    ])->assertStatus(201);

    $competencyId = DB::table('framework_competencies')->where('code', 'H5COMP2')->value('id');

    $count = h5CountOpenDraftExistenceQueries(function () use ($token, $competencyId): void {
        $this->withToken($token)->postJson('/api/catalogue/bars-indicators', [
            'competency_id' => $competencyId,
            'role_id' => null,
            'position' => 0,
            'text' => ['en' => 'x', 'it' => 'x'],
            'anchor_5' => ['en' => 'x', 'it' => 'x'],
            'anchor_3' => ['en' => 'x', 'it' => 'x'],
            'anchor_1' => ['en' => 'x', 'it' => 'x'],
        ])->assertStatus(201);
    });

    expect($count)->toBe(1);
});
