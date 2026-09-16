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
