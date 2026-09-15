<?php

declare(strict_types=1);

/**
 * REWRITTEN — framework-catalogue-authoring PR2, tasks.md 6.1 (D2): the
 * platform-wide `FrameworkVersion.is_locked` lock-guard this file used to
 * exercise is gone. `FrameworkCatalogSeeder`'s write gate is now keyed on the
 * baseline `FrameworkCatalogRevision`'s state and content, not on any
 * `FrameworkVersion` anywhere on the platform — `is_locked` has no bearing on
 * this seeder any more (see `FrameworkCatalogSeeder`'s own docblock, "Baseline-
 * revision write gate"). The old `FrameworkVersion::factory()->locked()`
 * scenarios this file used to build are not a "still relevant, keep as-is"
 * case for anything the new gate does, so there is nothing cross-tenant left
 * to assert here — the gate never reads `organization_id` or `is_locked` at
 * all, unlike the mechanism it replaces.
 *
 * Scenarios:
 * 1. A published baseline with NO content yet (the fresh-install shape —
 *    PR1's backfill migration always inserts the baseline as published, even
 *    against an empty catalogue, see PR2's contradiction-resolution
 *    annotation in tasks.md) is populated on the first run.
 * 2. A published baseline that already carries content performs ZERO writes
 *    on re-seed: neither an edited anchor nor a brand-new competency lands.
 * 3. The `seeder_lock_guard_active` signal (FrameworkGap + Log::warning) is
 *    emitted exactly when writes are blocked, and not otherwise.
 * 4. A forced draft baseline (state flipped via a raw `DB::table()` write —
 *    the ONLY way to construct one, since the Eloquent guard refuses to turn
 *    a published revision back to draft, and this schema never produces a
 *    draft baseline naturally) still runs the full delete-stale sync,
 *    matching the framework-catalog spec delta's "draft baseline still
 *    syncs" scenario literally.
 * 5. `framework_gaps` reconciliation proceeds regardless of the gate —
 *    bookkeeping, not catalogue content (D2).
 * 6. Idempotence and the per-role seeded counts (ICO 45, FLL 54, MLL 54,
 *    BUL 42, SRX 54) hold once the baseline is populated.
 *
 * Refs spec: framework-catalog spec delta, "Idempotent Catalog Seeder"
 * requirement (D2).
 */

use App\Models\BarsIndicator;
use App\Models\CatalogMeta;
use App\Models\Competency;
use App\Models\FrameworkCatalogRevision;
use App\Models\FrameworkGap;
use App\Models\Role;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// ─── helpers ─────────────────────────────────────────────────────────────────

function seederLockGuardBaseline(): FrameworkCatalogRevision
{
    return FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();
}

/**
 * Build a full fixture tree copied from the real wrapper catalog, so a test
 * can mutate an anchor or add a competency without touching the real source
 * files. Merges $extraCompetencies into competencies.json when provided.
 *
 * @param  array<string, array{name: array<string,string>, definition: array<string,string>}>  $extraCompetencies
 * @return array{string, string, string} [rolesFile, competenciesFile, barsDir]
 */
function buildSeederLockGuardFixtureTree(string $tmpDir, array $extraCompetencies = []): array
{
    $frameworkBase = dirname(base_path()).'/docs/app_description/02-domain/framework';
    @mkdir("{$tmpDir}/bars", 0755, true);

    $competencies = json_decode(file_get_contents("{$frameworkBase}/competencies.json"), true, 512, JSON_THROW_ON_ERROR);
    $competencies = array_merge($competencies, $extraCompetencies);
    file_put_contents("{$tmpDir}/competencies.json", json_encode($competencies));
    file_put_contents("{$tmpDir}/roles.json", file_get_contents("{$frameworkBase}/roles.json"));

    foreach (glob("{$frameworkBase}/bars/*.json") as $barsFile) {
        copy($barsFile, "{$tmpDir}/bars/".basename($barsFile));
    }

    return ["{$tmpDir}/roles.json", "{$tmpDir}/competencies.json", "{$tmpDir}/bars"];
}

function cleanupSeederLockGuardFixtureTree(string $tmpDir): void
{
    array_map('unlink', glob("{$tmpDir}/bars/*") ?: []);
    @rmdir("{$tmpDir}/bars");
    array_map('unlink', glob("{$tmpDir}/*.json") ?: []);
    @rmdir($tmpDir);
}

// ─── Scenario 1: fresh install — published-but-empty baseline is seeded ──────

test('a published baseline with no content yet is populated on the first run', function (): void {
    $baseline = seederLockGuardBaseline();
    expect($baseline->state)->toBe('published');
    expect(Competency::where('revision_id', $baseline->id)->exists())->toBeFalse();

    (new FrameworkCatalogSeeder)->run();

    expect(Competency::where('revision_id', $baseline->id)->exists())->toBeTrue();
    expect(Role::where('revision_id', $baseline->id)->where('code', 'ICO')->exists())->toBeTrue();
    expect(BarsIndicator::where('revision_id', $baseline->id)->exists())->toBeTrue();
    // The baseline itself is untouched by the seeder — still published, not mutated.
    expect($baseline->fresh()->state)->toBe('published');
});

// ─── Scenario 2: published + content → zero writes ────────────────────────────

test('a published baseline that already carries content performs zero writes on re-seed', function (): void {
    (new FrameworkCatalogSeeder)->run(); // populates the published-but-empty baseline

    $baseline = seederLockGuardBaseline();
    $ico = Role::where('revision_id', $baseline->id)->where('code', 'ICO')->firstOrFail();
    $pivotRow = DB::table('framework_role_competency')
        ->where('framework_role_competency.role_id', $ico->id)
        ->where('framework_role_competency.revision_id', $baseline->id)
        ->join('framework_competencies', 'framework_competencies.id', '=', 'framework_role_competency.competency_id')
        ->first();
    $competencyCode = $pivotRow->code;
    $competencyId = $pivotRow->competency_id;

    $originalIndicator = BarsIndicator::where('revision_id', $baseline->id)
        ->where('role_id', $ico->id)
        ->where('competency_id', $competencyId)
        ->orderBy('position')
        ->firstOrFail();
    $originalAnchor5 = $originalIndicator->getTranslation('anchor_5', 'en');
    $competencyCountBefore = Competency::where('revision_id', $baseline->id)->count();
    $indicatorCountBefore = BarsIndicator::where('revision_id', $baseline->id)->count();

    $tmpDir = sys_get_temp_dir().'/pr2_lock_guard_'.uniqid();
    [$rolesFile, $competenciesFile, $barsDir] = buildSeederLockGuardFixtureTree($tmpDir, [
        'ZZZ' => ['name' => ['en' => 'Test Competency Z'], 'definition' => ['en' => 'Test definition']],
    ]);

    // Edit an existing anchor — must not land.
    $barsFilePath = "{$barsDir}/ICO.json";
    $barsData = json_decode(file_get_contents($barsFilePath), true, 512, JSON_THROW_ON_ERROR);
    $barsData[$competencyCode][0]['scale']['5']['en'] = 'MODIFIED ANCHOR TEXT — MUST NOT LAND';
    file_put_contents($barsFilePath, json_encode($barsData));

    (new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir))->run();

    expect($originalIndicator->fresh()->getTranslation('anchor_5', 'en'))->toBe($originalAnchor5);
    expect(Competency::where('code', 'ZZZ')->exists())->toBeFalse('a brand-new competency must never be created while writes are blocked');
    expect(Competency::where('revision_id', $baseline->id)->count())->toBe($competencyCountBefore);
    expect(BarsIndicator::where('revision_id', $baseline->id)->count())->toBe($indicatorCountBefore);

    cleanupSeederLockGuardFixtureTree($tmpDir);
});

// ─── Scenario 3: seeder_lock_guard_active signal ───────────────────────────────

test('the seeder_lock_guard_active signal is emitted only while writes are blocked', function (): void {
    (new FrameworkCatalogSeeder)->run(); // first run: published-but-empty — writes NOT blocked
    expect(FrameworkGap::where('kind', 'seeder_lock_guard_active')->exists())->toBeFalse();

    Log::spy();

    (new FrameworkCatalogSeeder)->run(); // second run: published-with-content — writes blocked

    expect(FrameworkGap::where('kind', 'seeder_lock_guard_active')->exists())->toBeTrue();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'baseline revision is published and already carries content'))
        ->once();
});

// ─── Scenario 4: a forced draft baseline still runs the full sync ─────────────

test('a draft baseline still runs the full delete-stale sync', function (): void {
    (new FrameworkCatalogSeeder)->run(); // populate the published baseline first

    $baseline = seederLockGuardBaseline();

    // The ONLY way to construct a draft baseline in this schema: bypass the
    // Eloquent immutability guard with a raw query-builder write. Model
    // events never fire for this, which is the point — it proves the SEEDER's
    // own gate, not the model guard, decides whether this run writes.
    DB::table('framework_catalog_revisions')
        ->where('id', $baseline->id)
        ->update(['state' => 'draft', 'published_at' => null]);

    $ico = Role::where('revision_id', $baseline->id)->where('code', 'ICO')->firstOrFail();
    $pivotRow = DB::table('framework_role_competency')
        ->where('framework_role_competency.role_id', $ico->id)
        ->where('framework_role_competency.revision_id', $baseline->id)
        ->join('framework_competencies', 'framework_competencies.id', '=', 'framework_role_competency.competency_id')
        ->first();
    $removedCode = $pivotRow->code;
    $removedId = $pivotRow->competency_id;

    $tmpDir = sys_get_temp_dir().'/pr2_lock_guard_draft_'.uniqid();
    $frameworkBase = dirname(base_path()).'/docs/app_description/02-domain/framework';
    $originalRoles = json_decode(file_get_contents("{$frameworkBase}/roles.json"), true, 512, JSON_THROW_ON_ERROR);
    $originalRoles['ICO']['competencies'] = array_values(
        array_filter($originalRoles['ICO']['competencies'], fn ($c) => $c !== $removedCode)
    );

    @mkdir("{$tmpDir}/bars", 0755, true);
    file_put_contents("{$tmpDir}/roles.json", json_encode($originalRoles));
    file_put_contents("{$tmpDir}/competencies.json", file_get_contents("{$frameworkBase}/competencies.json"));
    foreach (glob("{$frameworkBase}/bars/*.json") as $barsFile) {
        copy($barsFile, "{$tmpDir}/bars/".basename($barsFile));
    }

    (new FrameworkCatalogSeeder("{$tmpDir}/roles.json", "{$tmpDir}/competencies.json", "{$tmpDir}/bars"))->run();

    // A draft baseline runs the normal delete-stale sync — the removed pivot
    // assignment is gone, exactly as it would be for any non-blocked run.
    $pivotExists = DB::table('framework_role_competency')
        ->where('role_id', $ico->id)
        ->where('competency_id', $removedId)
        ->exists();
    expect($pivotExists)->toBeFalse('a draft baseline must run the full delete-stale sync, unchanged');

    cleanupSeederLockGuardFixtureTree($tmpDir);
});

// ─── Scenario 5: framework_gaps reconciliation proceeds regardless of the gate ──

test('framework_gaps reconciliation proceeds even while writes are blocked', function (): void {
    (new FrameworkCatalogSeeder)->run(); // populate — writes NOT blocked

    $baseline = seederLockGuardBaseline();
    $ico = Role::where('revision_id', $baseline->id)->where('code', 'ICO')->firstOrFail();
    $pivotRow = DB::table('framework_role_competency')
        ->where('framework_role_competency.role_id', $ico->id)
        ->where('framework_role_competency.revision_id', $baseline->id)
        ->join('framework_competencies', 'framework_competencies.id', '=', 'framework_role_competency.competency_id')
        ->first();

    // Force a stale pending gap for an already-anchored, already-fully-
    // translated pair (the real fixture's own ICO×first-competency pair),
    // mirroring the spec scenario "a framework_gaps row for a now-anchored
    // pair ... is still resolved". Written directly — the seeder itself
    // would never have created a pending row for a pair that already
    // satisfies the rule.
    FrameworkGap::updateOrCreate(
        ['kind' => 'missing_translation', 'role_code' => 'ICO', 'competency_code' => $pivotRow->code],
        ['note' => 'forced pending, for this test only', 'status' => 'pending_authoring'],
    );

    (new FrameworkCatalogSeeder)->run(); // writes ARE blocked now (published + content)

    expect(
        FrameworkGap::where('kind', 'missing_translation')
            ->where('role_code', 'ICO')
            ->where('competency_code', $pivotRow->code)
            ->where('status', 'pending_authoring')
            ->exists()
    )->toBeFalse('gap reconciliation must resolve an already-satisfied pending gap even while catalogue writes are blocked');
});

// ─── Scenario 6: idempotence + per-role seeded counts ──────────────────────────

test('per-role seeded counts are correct once the baseline is populated', function (): void {
    (new FrameworkCatalogSeeder)->run();

    $baseline = seederLockGuardBaseline();

    $counts = DB::table('framework_bars_indicators')
        ->join('framework_roles', 'framework_roles.id', '=', 'framework_bars_indicators.role_id')
        ->where('framework_bars_indicators.revision_id', $baseline->id)
        ->selectRaw('framework_roles.code as role_code, count(*) as total')
        ->groupBy('framework_roles.code')
        ->pluck('total', 'role_code');

    expect($counts['ICO'])->toBe(45);
    expect($counts['FLL'])->toBe(54);
    expect($counts['MLL'])->toBe(54);
    expect($counts['BUL'])->toBe(42);
    expect($counts['SRX'])->toBe(54);
});

test('re-seeding an already-populated published baseline produces no duplicates (idempotence via the write gate)', function (): void {
    (new FrameworkCatalogSeeder)->run();

    $baseline = seederLockGuardBaseline();
    $competencyCountBefore = Competency::where('revision_id', $baseline->id)->count();
    $indicatorCountBefore = BarsIndicator::where('revision_id', $baseline->id)->count();
    $roleCountBefore = Role::where('revision_id', $baseline->id)->count();

    (new FrameworkCatalogSeeder)->run();
    (new FrameworkCatalogSeeder)->run();

    expect(Competency::where('revision_id', $baseline->id)->count())->toBe($competencyCountBefore);
    expect(BarsIndicator::where('revision_id', $baseline->id)->count())->toBe($indicatorCountBefore);
    expect(Role::where('revision_id', $baseline->id)->count())->toBe($roleCountBefore);
});

test('CatalogMeta does not bump on a blocked re-seed', function (): void {
    (new FrameworkCatalogSeeder)->run();
    $bumpBefore = CatalogMeta::first()?->revision ?? 0;

    (new FrameworkCatalogSeeder)->run(); // blocked — no structural change

    $bumpAfter = CatalogMeta::first()?->revision ?? 0;
    expect($bumpAfter)->toBe($bumpBefore, 'CatalogMeta must not bump when the write gate blocks the whole run');
});
