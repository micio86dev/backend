<?php

declare(strict_types=1);

/**
 * With no open draft, the catalogue list endpoints return the latest
 * published revision's rows as a read-only view, and
 * `GET /catalogue/revisions/current` names that revision with
 * `editable: false`. `POST /catalogue/revisions/draft` opens a draft cloned
 * from it (or returns the one already open), after which every list returns
 * the draft's own rows and those ids are writable.
 */

use App\Models\FrameworkCatalogRevision;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\DB;

function pvSuperadmin(): User
{
    return User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
}

function pvSeedBaselineWithDefaultQuestion(): FrameworkCatalogRevision
{
    (new FrameworkCatalogSeeder)->run();

    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();

    DB::table('framework_default_questions')->insert([
        'revision_id' => $baseline->id,
        'competency_id' => DB::table('framework_competencies')->where('revision_id', $baseline->id)->orderBy('id')->value('id'),
        'text' => json_encode(['en' => 'Tell me about a time...', 'it' => 'Raccontami di una volta...']),
        'position' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $baseline;
}

/**
 * @return array<string, list<int>>
 */
function pvListedIds(string $token): array
{
    $ids = [];

    foreach (['roles', 'competencies', 'bars-indicators', 'default-questions'] as $list) {
        $ids[$list] = collect(test()->withToken($token)->getJson("/api/catalogue/{$list}")->assertOk()->json('data'))
            ->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
    }

    return $ids;
}

test('with no open draft, every list returns the latest published revision rows', function (): void {
    $baseline = pvSeedBaselineWithDefaultQuestion();
    $token = auth('api')->login(pvSuperadmin());

    expect(FrameworkCatalogRevision::openDraft())->toBeNull();

    $ids = pvListedIds($token);

    expect($ids['roles'])->toHaveCount(5)
        ->and($ids['competencies'])->toHaveCount(20)
        ->and($ids['bars-indicators'])->toHaveCount(255)
        ->and($ids['default-questions'])->toHaveCount(1);

    expect($ids['roles'])->toBe(DB::table('framework_roles')->where('revision_id', $baseline->id)->orderBy('id')->pluck('id')->all());

    // Roles carry their competency set in the read-only view too.
    $role = collect($this->withToken($token)->getJson('/api/catalogue/roles')->json('data'))->firstWhere('code', 'ICO');
    expect($role['competency_ids'])->toHaveCount(15);

    $this->withToken($token)->getJson('/api/catalogue/revisions/current')
        ->assertOk()
        ->assertJsonPath('data.id', $baseline->id)
        ->assertJsonPath('data.state', 'published')
        ->assertJsonPath('data.editable', false);

    // Reading never opens a draft.
    expect(FrameworkCatalogRevision::openDraft())->toBeNull();
});

test('with an open draft, every list returns the draft rows and current reports it as editable', function (): void {
    $baseline = pvSeedBaselineWithDefaultQuestion();
    $token = auth('api')->login(pvSuperadmin());

    $draftId = $this->withToken($token)->postJson('/api/catalogue/revisions/draft')->assertCreated()->json('data.id');

    $ids = pvListedIds($token);

    expect($ids['roles'])->toBe(DB::table('framework_roles')->where('revision_id', $draftId)->orderBy('id')->pluck('id')->all())
        ->and($ids['competencies'])->toBe(DB::table('framework_competencies')->where('revision_id', $draftId)->orderBy('id')->pluck('id')->all())
        ->and($ids['bars-indicators'])->toHaveCount(255)
        ->and($ids['default-questions'])->toHaveCount(1);

    expect(DB::table('framework_bars_indicators')->whereIn('id', $ids['bars-indicators'])->where('revision_id', '!=', $draftId)->exists())->toBeFalse();
    expect(array_intersect($ids['roles'], DB::table('framework_roles')->where('revision_id', $baseline->id)->pluck('id')->all()))->toBe([]);

    $this->withToken($token)->getJson('/api/catalogue/revisions/current')
        ->assertOk()
        ->assertJsonPath('data.id', $draftId)
        ->assertJsonPath('data.state', 'draft')
        ->assertJsonPath('data.editable', true);
});

test('opening a draft is idempotent and audited once', function (): void {
    $baseline = pvSeedBaselineWithDefaultQuestion();
    $user = pvSuperadmin();
    $token = auth('api')->login($user);

    $first = $this->withToken($token)->postJson('/api/catalogue/revisions/draft')
        ->assertCreated()
        ->assertJsonPath('data.state', 'draft')
        ->assertJsonPath('data.editable', true)
        ->assertJsonPath('data.parent_revision_id', $baseline->id)
        ->json('data.id');

    $second = $this->withToken($token)->postJson('/api/catalogue/revisions/draft')
        ->assertOk()
        ->json('data.id');

    expect($second)->toBe($first);
    expect(FrameworkCatalogRevision::where('state', 'draft')->count())->toBe(1);
    expect(DB::table('framework_roles')->where('revision_id', $first)->count())->toBe(5);

    $audit = DB::table('audit_logs')->where('action', 'revision.draft_opened')->get();

    expect($audit)->toHaveCount(1);
    expect($audit[0]->organization_id)->toBeNull()
        ->and((int) $audit[0]->actor_id)->toBe($user->id)
        ->and($audit[0]->subject_type)->toBe('FrameworkCatalogRevision')
        ->and((int) $audit[0]->subject_id)->toBe($first)
        ->and(json_decode((string) $audit[0]->after, true))->toBe(['revision_id' => $first, 'parent_revision_id' => $baseline->id]);
});

test('opening a draft is refused with 403 for a non-superadmin and opens nothing', function (): void {
    pvSeedBaselineWithDefaultQuestion();

    $org = Organization::factory()->create();
    $admin = User::factory()->create(['organization_id' => $org->id, 'is_superadmin' => false]);

    $this->withToken(auth('api')->login($admin))
        ->postJson('/api/catalogue/revisions/draft')
        ->assertForbidden();

    expect(FrameworkCatalogRevision::openDraft())->toBeNull();
    expect(DB::table('audit_logs')->where('action', 'revision.draft_opened')->exists())->toBeFalse();
});

test('a published row id is not writable, and the cloned row is after opening a draft', function (): void {
    $baseline = pvSeedBaselineWithDefaultQuestion();
    $token = auth('api')->login(pvSuperadmin());

    $publishedCompetency = collect($this->withToken($token)->getJson('/api/catalogue/competencies')->json('data'))->firstWhere('code', 'COL');

    $this->withToken($token)
        ->patchJson("/api/catalogue/competencies/{$publishedCompetency['id']}", ['name' => ['en' => 'Renamed', 'it' => 'Rinominata']])
        ->assertNotFound();

    $this->withToken($token)->postJson('/api/catalogue/revisions/draft')->assertCreated();

    // The published id stays unwritable even with a draft open.
    $this->withToken($token)
        ->patchJson("/api/catalogue/competencies/{$publishedCompetency['id']}", ['name' => ['en' => 'Renamed', 'it' => 'Rinominata']])
        ->assertNotFound();

    $draftCompetency = collect($this->withToken($token)->getJson('/api/catalogue/competencies')->json('data'))->firstWhere('code', 'COL');

    expect($draftCompetency['id'])->not->toBe($publishedCompetency['id']);

    $this->withToken($token)
        ->patchJson("/api/catalogue/competencies/{$draftCompetency['id']}", ['name' => ['en' => 'Renamed', 'it' => 'Rinominata']])
        ->assertOk()
        ->assertJsonPath('data.name.en', 'Renamed');

    expect(json_decode((string) DB::table('framework_competencies')->where('id', $publishedCompetency['id'])->value('name'), true)['en'])
        ->not->toBe('Renamed');
    expect(FrameworkCatalogRevision::find($baseline->id)?->state)->toBe('published');
});
