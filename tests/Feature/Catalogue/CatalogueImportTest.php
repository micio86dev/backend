<?php

declare(strict_types=1);

/**
 * RED/GREEN — 15.5 (framework-catalogue-authoring PR4, task 15.5, OQ-A —
 * resolved by the product owner 2026-09-15): `catalogue:import --into-draft`
 * reads the same split-file shape `catalogue:export` prints, writes ONLY
 * into the open draft revision, and never publishes.
 *
 * The round-trip test feeds `catalogue:export`'s own output back in, split
 * into the exact multi-file tree `FrameworkCatalogSeeder`/`catalogue:import`
 * read (`roles.json`, `competencies.json`, `bars/{ROLE}.json`, plus
 * `bars/POTENTIAL.json` for role-less indicators) — proving the two commands
 * are genuinely symmetric, not merely both individually plausible.
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Exports `$revisionId` and splits the merged envelope back into the exact
 * multi-file tree `catalogue:import`/`FrameworkCatalogSeeder` read, in a
 * fresh temp directory. Returns that directory's path.
 */
function catalogueImportSourceTreeFor(int $revisionId): string
{
    Artisan::call('catalogue:export', ['revision' => $revisionId]);
    /** @var array<string, mixed> $export */
    $export = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    $dir = sys_get_temp_dir().'/catalogue-import-'.uniqid('', true);
    mkdir("{$dir}/bars", recursive: true);

    file_put_contents("{$dir}/roles.json", json_encode($export['roles'], JSON_THROW_ON_ERROR));
    file_put_contents("{$dir}/competencies.json", json_encode($export['competencies'], JSON_THROW_ON_ERROR));

    foreach ($export['bars'] as $code => $barsForRole) {
        file_put_contents("{$dir}/bars/{$code}.json", json_encode($barsForRole, JSON_THROW_ON_ERROR));
    }

    return $dir;
}

test('export then import round-trips a revision into a draft unchanged', function (): void {
    (new FrameworkCatalogSeeder)->run();

    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    $dir = catalogueImportSourceTreeFor($baseline->id);

    Artisan::call('catalogue:import', ['--into-draft' => true, '--path' => $dir]);

    $draft = FrameworkCatalogRevision::where('state', 'draft')->firstOrFail();

    // Codes: same set of role and competency codes.
    expect(Role::where('revision_id', $draft->id)->pluck('code')->sort()->values()->all())
        ->toBe(Role::where('revision_id', $baseline->id)->pluck('code')->sort()->values()->all());
    expect(Competency::where('revision_id', $draft->id)->pluck('code')->sort()->values()->all())
        ->toBe(Competency::where('revision_id', $baseline->id)->pluck('code')->sort()->values()->all());

    // Locale maps + pivot: every role's name/responsibilities/assigned
    // competency codes (in authored order) match the source revision.
    foreach (Role::where('revision_id', $baseline->id)->get() as $sourceRole) {
        $draftRole = Role::where('revision_id', $draft->id)->where('code', $sourceRole->code)->firstOrFail();

        expect($draftRole->getTranslations('name'))->toBe($sourceRole->getTranslations('name'));
        expect($draftRole->getTranslations('responsibilities'))->toBe($sourceRole->getTranslations('responsibilities'));
        expect($draftRole->competencies()->pluck('code')->values()->all())
            ->toBe($sourceRole->competencies()->pluck('code')->values()->all());
    }

    // Positions + locale maps for every role-scoped indicator.
    foreach (BarsIndicator::where('revision_id', $baseline->id)->whereNotNull('role_id')->get() as $sourceIndicator) {
        $roleCode = Role::find($sourceIndicator->role_id)->code;
        $competencyCode = Competency::find($sourceIndicator->competency_id)->code;

        $draftRoleId = Role::where('revision_id', $draft->id)->where('code', $roleCode)->value('id');
        $draftCompetencyId = Competency::where('revision_id', $draft->id)->where('code', $competencyCode)->value('id');

        $draftIndicator = BarsIndicator::where('revision_id', $draft->id)
            ->where('role_id', $draftRoleId)
            ->where('competency_id', $draftCompetencyId)
            ->where('position', $sourceIndicator->position)
            ->first();

        expect($draftIndicator)->not->toBeNull();
        expect($draftIndicator->getTranslations('text'))->toBe($sourceIndicator->getTranslations('text'));
        expect($draftIndicator->getTranslations('anchor_5'))->toBe($sourceIndicator->getTranslations('anchor_5'));
    }

    // Role-less indicators stay role-less.
    $sourceRoleLessCount = BarsIndicator::where('revision_id', $baseline->id)->whereNull('role_id')->count();
    $draftRoleLessCount = BarsIndicator::where('revision_id', $draft->id)->whereNull('role_id')->count();
    expect($sourceRoleLessCount)->toBeGreaterThan(0);
    expect($draftRoleLessCount)->toBe($sourceRoleLessCount);

    // Never published, never touches the published source revision.
    expect($draft->state)->toBe('draft');
    expect($baseline->fresh()->state)->toBe('published');
});

test('import refuses to write a published revision — only the open draft is ever a target', function (): void {
    (new FrameworkCatalogSeeder)->run();
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    $dir = catalogueImportSourceTreeFor($baseline->id);

    $publishedCountBefore = FrameworkCatalogRevision::where('state', 'published')->count();

    Artisan::call('catalogue:import', ['--into-draft' => true, '--path' => $dir]);

    expect(FrameworkCatalogRevision::where('state', 'published')->count())->toBe($publishedCountBefore);
    expect(FrameworkCatalogRevision::where('state', 'draft')->count())->toBe(1);
});

test('import without --into-draft refuses cleanly, naming the only supported target', function (): void {
    (new FrameworkCatalogSeeder)->run();
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    $dir = catalogueImportSourceTreeFor($baseline->id);

    $exitCode = Artisan::call('catalogue:import', ['--path' => $dir]);

    expect($exitCode)->not->toBe(0);
    expect(FrameworkCatalogRevision::where('state', 'draft')->exists())->toBeFalse();
});

test('K1: a new pivot attachment written by import lands in the draft, never the baseline', function (): void {
    // RED/GREEN — K1 (framework-catalogue-authoring PR4b, R3-import-pivot-
    // revision): `Role::competencies()->sync()` writes pivot rows with no
    // `revision_id` pivot attribute, so a NEWLY-ATTACHED pair (one the
    // freshly-cloned draft did not already carry) took the column DEFAULT —
    // a fixed baseline id baked in at migration time — instead of the
    // draft's own id. Modifying `roles.json` to assign an EXTRA competency
    // to an existing role is what forces `sync()` to actually INSERT a new
    // pivot row rather than merely UPDATE an already-cloned one's position.
    (new FrameworkCatalogSeeder)->run();
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    $dir = catalogueImportSourceTreeFor($baseline->id);

    /** @var array<string, array{competencies?: list<string>}> $rolesJson */
    $rolesJson = json_decode((string) file_get_contents("{$dir}/roles.json"), true, 512, JSON_THROW_ON_ERROR);
    /** @var array<string, mixed> $competenciesJson */
    $competenciesJson = json_decode((string) file_get_contents("{$dir}/competencies.json"), true, 512, JSON_THROW_ON_ERROR);

    $firstRoleCode = array_key_first($rolesJson);
    $alreadyAssigned = $rolesJson[$firstRoleCode]['competencies'] ?? [];
    $unassignedCompetencyCode = null;

    foreach (array_keys($competenciesJson) as $code) {
        if (! in_array($code, $alreadyAssigned, true)) {
            $unassignedCompetencyCode = $code;
            break;
        }
    }

    expect($unassignedCompetencyCode)->not->toBeNull();

    $rolesJson[$firstRoleCode]['competencies'][] = $unassignedCompetencyCode;
    file_put_contents("{$dir}/roles.json", json_encode($rolesJson, JSON_THROW_ON_ERROR));

    $baselinePivotCountBefore = DB::table('framework_role_competency')->where('revision_id', $baseline->id)->count();

    $exitCode = Artisan::call('catalogue:import', ['--into-draft' => true, '--path' => $dir]);
    expect($exitCode)->toBe(0);

    $draft = FrameworkCatalogRevision::where('state', 'draft')->firstOrFail();
    $draftRoleId = Role::where('revision_id', $draft->id)->where('code', $firstRoleCode)->value('id');
    $draftCompetencyId = Competency::where('revision_id', $draft->id)->where('code', $unassignedCompetencyCode)->value('id');

    $newPivotRevisionId = DB::table('framework_role_competency')
        ->where('role_id', $draftRoleId)
        ->where('competency_id', $draftCompetencyId)
        ->value('revision_id');

    expect($newPivotRevisionId)->toBe($draft->id);
    expect(DB::table('framework_role_competency')->where('revision_id', $baseline->id)->count())
        ->toBe($baselinePivotCountBefore);
});

test('K4: a malformed bars file leaves no orphan draft behind', function (): void {
    // RED/GREEN — K4 (framework-catalogue-authoring PR4b, R3-import-orphan-
    // draft, R4-import-orphan-clone): `OpenDraftRevision::open()` used to
    // commit the clone in its OWN transaction, BEFORE the import's own
    // `DB::transaction()` even starts — a malformed `bars/{ROLE}.json` file
    // (read and JSON-decoded INSIDE that later transaction) then rolled
    // back only the partial content writes, leaving the already-committed
    // clone occupying the platform's single draft slot with nothing in it
    // that reflects the source files at all.
    (new FrameworkCatalogSeeder)->run();
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    $dir = catalogueImportSourceTreeFor($baseline->id);

    $barsFiles = glob("{$dir}/bars/*.json");
    expect($barsFiles)->not->toBeEmpty();
    // POTENTIAL.json is optional and role-less; corrupt a genuine ROLE file
    // so `importRolesAndBars()` — not the optional
    // `importPotentialBars()` — is what throws.
    $roleBarsFile = collect($barsFiles)->first(fn (string $path) => ! str_ends_with($path, 'POTENTIAL.json'));
    expect($roleBarsFile)->not->toBeNull();
    file_put_contents($roleBarsFile, '{not valid json');

    expect(FrameworkCatalogRevision::where('state', 'draft')->exists())->toBeFalse();
    $revisionCountBefore = FrameworkCatalogRevision::count();

    // A malformed source file throws (JSON_THROW_ON_ERROR), same as a
    // malformed roles.json/competencies.json already does at the top of
    // handle() — this command has never caught a parse failure into a clean
    // exit code, and K4 does not change that. What K4 closes is what
    // survives the throw: no orphan draft.
    expect(fn () => Artisan::call('catalogue:import', ['--into-draft' => true, '--path' => $dir]))
        ->toThrow(JsonException::class);

    expect(FrameworkCatalogRevision::where('state', 'draft')->exists())->toBeFalse();
    expect(FrameworkCatalogRevision::count())->toBe($revisionCountBefore);
});

test('import refuses a draft that already carries unrelated edits, unless told to continue', function (): void {
    (new FrameworkCatalogSeeder)->run();
    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
    $dir = catalogueImportSourceTreeFor($baseline->id);

    // A superadmin edit already opened the draft and wrote real content —
    // content_version is therefore non-zero (the same signal
    // `DiscardUnusedDraftRevision` uses for "genuinely untouched"). A new
    // competency, not a 6th role — the five roles are a closed set
    // (`CatalogueRules::MAX_ROLES`) and the seeded baseline already has all
    // five.
    $user = User::factory()->create(['organization_id' => null, 'is_superadmin' => true]);
    $token = auth('api')->login($user);
    $this->withToken($token)->postJson('/api/catalogue/competencies', [
        'code' => 'DIRTYDRAFT',
        'type' => 'standard',
        'name' => ['en' => 'x', 'it' => 'x'],
        'definition' => ['en' => 'x', 'it' => 'x'],
    ])->assertStatus(201);

    $draft = FrameworkCatalogRevision::openDraft();
    expect($draft->content_version)->toBeGreaterThan(0);

    $exitCode = Artisan::call('catalogue:import', ['--into-draft' => true, '--path' => $dir]);

    expect($exitCode)->not->toBe(0);
    expect(Competency::where('revision_id', $draft->id)->where('code', 'DIRTYDRAFT')->exists())->toBeTrue();
    expect(FrameworkCatalogRevision::where('state', 'draft')->count())->toBe(1);

    // --continue explicitly accepts writing into the dirty draft anyway.
    $exitCodeContinue = Artisan::call('catalogue:import', ['--into-draft' => true, '--continue' => true, '--path' => $dir]);

    expect($exitCodeContinue)->toBe(0);
    expect(Competency::where('revision_id', $draft->id)->where('code', 'DIRTYDRAFT')->exists())->toBeTrue();
    expect(Role::where('revision_id', $draft->id)->count())->toBeGreaterThan(0);
});
