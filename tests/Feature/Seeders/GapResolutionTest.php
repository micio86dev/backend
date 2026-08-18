<?php

declare(strict_types=1);

/**
 * RED — 0.1: Gap row reconciliation (C3 fix 5a).
 *
 * `FrameworkCatalogSeeder` `updateOrCreate`s `framework_gaps` rows
 * (`missing_role_meta`, `role_no_bars`, `competency_no_bars`) but never
 * resolves them — nothing ever moves `status` away from
 * `pending_authoring` once the underlying gap closes. These tests prove
 * that today (RED); task 0.2 drives all four resolution points to
 * `status = 'resolved'`.
 *
 * Refs: design.md §5a; spec framework-catalog delta "Gap Row Reconciliation
 * on Seeded Content".
 */

use App\Models\FrameworkGap;
use Database\Seeders\FrameworkCatalogSeeder;

/**
 * Build a full fixture tree (roles.json, competencies.json, bars/) copied
 * from the real wrapper catalog, so it can be mutated freely per test
 * without touching the real authored source.
 *
 * @param  array<string, array<string, mixed>>  $roleOverrides  Merged onto the matching role entry
 * @return array{string, string, string} [rolesFile, competenciesFile, barsDir]
 */
function buildGapResolutionFixtures(string $tmpDir, array $roleOverrides = []): array
{
    $frameworkBase = dirname(base_path()).'/docs/app_description/02-domain/framework';
    $roles = json_decode(file_get_contents("{$frameworkBase}/roles.json"), true, 512, JSON_THROW_ON_ERROR);

    foreach ($roleOverrides as $code => $override) {
        $roles[$code] = array_merge($roles[$code] ?? [], $override);
    }

    @mkdir("{$tmpDir}/bars", 0755, true);
    file_put_contents("{$tmpDir}/roles.json", json_encode($roles));
    file_put_contents("{$tmpDir}/competencies.json", file_get_contents("{$frameworkBase}/competencies.json"));

    foreach (glob("{$frameworkBase}/bars/*.json") as $barsFile) {
        copy($barsFile, "{$tmpDir}/bars/".basename($barsFile));
    }

    return ["{$tmpDir}/roles.json", "{$tmpDir}/competencies.json", "{$tmpDir}/bars"];
}

function cleanupGapResolutionFixtures(string $tmpDir): void
{
    array_map('unlink', glob("{$tmpDir}/bars/*") ?: []);
    @rmdir("{$tmpDir}/bars");
    array_map('unlink', glob("{$tmpDir}/*.json") ?: []);
    @rmdir($tmpDir);
}

test('missing_role_meta gap resolves once responsibilities becomes non-empty', function (): void {
    $tmpDir = sys_get_temp_dir().'/c3_gap_role_meta_'.uniqid();

    // First seed — SRX responsibilities forced empty (pre-change production state).
    [$rolesFile, $competenciesFile, $barsDir] = buildGapResolutionFixtures($tmpDir, [
        'SRX' => ['responsibilities' => ['en' => '']],
    ]);
    $seeder = new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir);
    $seeder->run();

    expect(
        FrameworkGap::where('kind', 'missing_role_meta')
            ->where('role_code', 'SRX')
            ->where('status', 'pending_authoring')
            ->exists()
    )->toBeTrue('gap must start pending_authoring');

    // Author responsibilities in the JSON and re-seed.
    $roles = json_decode(file_get_contents($rolesFile), true, 512, JSON_THROW_ON_ERROR);
    $roles['SRX']['responsibilities'] = ['en' => 'Now authored.'];
    file_put_contents($rolesFile, json_encode($roles));

    $seeder->run();

    expect(
        FrameworkGap::where('kind', 'missing_role_meta')
            ->where('role_code', 'SRX')
            ->where('status', 'pending_authoring')
            ->exists()
    )->toBeFalse('gap must no longer be pending_authoring once responsibilities is authored');

    cleanupGapResolutionFixtures($tmpDir);
});

test('role_no_bars gap resolves once bars/{ROLE}.json exists', function (): void {
    $tmpDir = sys_get_temp_dir().'/c3_gap_role_no_bars_'.uniqid();

    // SRX now has bars/SRX.json in the real tree as of
    // bars-catalogue-completion Phase 6 (every declared role does), so this
    // test's precondition — a role with no bars file — is manufactured in
    // the FIXTURE COPY only, by deleting SRX's bars file back out. This
    // keeps the test decoupled from which role happens to be missing its
    // bars file in the real tree at any given time (the same pattern the
    // competency_no_bars tests below already use for FLL:PRS).
    [$rolesFile, $competenciesFile, $barsDir] = buildGapResolutionFixtures($tmpDir);
    unlink("{$barsDir}/SRX.json");
    expect(is_file("{$barsDir}/SRX.json"))->toBeFalse('fixture precondition: SRX.json absent (manufactured)');

    $seeder = new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir);
    $seeder->run();

    expect(
        FrameworkGap::where('kind', 'role_no_bars')
            ->where('role_code', 'SRX')
            ->where('status', 'pending_authoring')
            ->exists()
    )->toBeTrue('gap must start pending_authoring');

    // Author a minimal SRX.json and re-seed.
    file_put_contents("{$barsDir}/SRX.json", json_encode([
        'PRS' => [[
            'indicator' => ['en' => 'Test indicator'],
            'scale' => ['5' => ['en' => 'A5'], '3' => ['en' => 'A3'], '1' => ['en' => 'A1']],
        ]],
    ]));

    $seeder->run();

    expect(
        FrameworkGap::where('kind', 'role_no_bars')
            ->where('role_code', 'SRX')
            ->where('status', 'pending_authoring')
            ->exists()
    )->toBeFalse('gap must no longer be pending_authoring once the bars file exists');

    cleanupGapResolutionFixtures($tmpDir);
});

test('competency_no_bars gap resolves once the pair is covered', function (): void {
    $tmpDir = sys_get_temp_dir().'/c3_gap_comp_no_bars_'.uniqid();

    [$rolesFile, $competenciesFile, $barsDir] = buildGapResolutionFixtures($tmpDir);

    // FLL:PRS is fully anchored in the real catalogue as of
    // bars-catalogue-completion Phase 4 (FLL is now complete at 18/18
    // assigned competencies), so this test's precondition — an unanchored
    // pair — is manufactured in the FIXTURE COPY only, by stripping the
    // PRS entry back out. This keeps the test decoupled from which pairs
    // happen to be anchored in the real tree at any given time.
    $fllBarsInitial = json_decode(file_get_contents("{$barsDir}/FLL.json"), true, 512, JSON_THROW_ON_ERROR);
    unset($fllBarsInitial['PRS']);
    file_put_contents("{$barsDir}/FLL.json", json_encode($fllBarsInitial));

    $seeder = new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir);
    $seeder->run();

    expect(
        FrameworkGap::where('kind', 'competency_no_bars')
            ->where('role_code', 'FLL')
            ->where('competency_code', 'PRS')
            ->where('status', 'pending_authoring')
            ->exists()
    )->toBeTrue('gap must start pending_authoring');

    // Author the pair into the fixture copy and re-seed.
    $fllBars = json_decode(file_get_contents("{$barsDir}/FLL.json"), true, 512, JSON_THROW_ON_ERROR);
    $fllBars['PRS'] = [[
        'indicator' => ['en' => 'Test indicator'],
        'scale' => ['5' => ['en' => 'A5'], '3' => ['en' => 'A3'], '1' => ['en' => 'A1']],
    ]];
    file_put_contents("{$barsDir}/FLL.json", json_encode($fllBars));

    $seeder->run();

    expect(
        FrameworkGap::where('kind', 'competency_no_bars')
            ->where('role_code', 'FLL')
            ->where('competency_code', 'PRS')
            ->where('status', 'pending_authoring')
            ->exists()
    )->toBeFalse('gap must no longer be pending_authoring once the pair is covered');

    cleanupGapResolutionFixtures($tmpDir);
});

test('competency_no_bars gap preserves row identity through a reopen after resolution', function (): void {
    // Verifies the seeder's own justification for status='resolved' over
    // delete (design.md §5a): a reopened gap must be indistinguishable from
    // a resolved-then-reopened row only by re-reading the SAME row, not by
    // creating a fresh one — because updateOrCreate's match keys are
    // (kind, role_code, competency_code), never status.
    $tmpDir = sys_get_temp_dir().'/c3_gap_comp_reopen_'.uniqid();

    [$rolesFile, $competenciesFile, $barsDir] = buildGapResolutionFixtures($tmpDir);

    // Same manufactured precondition as the sibling tests above: strip
    // FLL:PRS out of the fixture copy so the pair starts unanchored.
    $fllBarsInitial = json_decode(file_get_contents("{$barsDir}/FLL.json"), true, 512, JSON_THROW_ON_ERROR);
    unset($fllBarsInitial['PRS']);
    file_put_contents("{$barsDir}/FLL.json", json_encode($fllBarsInitial));

    $seeder = new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir);
    $seeder->run();

    $initialGap = FrameworkGap::where('kind', 'competency_no_bars')
        ->where('role_code', 'FLL')
        ->where('competency_code', 'PRS')
        ->first();

    expect($initialGap)->not->toBeNull('gap row must exist after the first seed');
    expect($initialGap->status)->toBe('pending_authoring');

    $originalId = $initialGap->id;
    $originalCreatedAt = $initialGap->created_at->toIso8601String();

    // Author the pair and re-seed — the row must be marked resolved, not replaced.
    $fllBarsAuthored = json_decode(file_get_contents("{$barsDir}/FLL.json"), true, 512, JSON_THROW_ON_ERROR);
    $fllBarsAuthored['PRS'] = [[
        'indicator' => ['en' => 'Test indicator'],
        'scale' => ['5' => ['en' => 'A5'], '3' => ['en' => 'A3'], '1' => ['en' => 'A1']],
    ]];
    file_put_contents("{$barsDir}/FLL.json", json_encode($fllBarsAuthored));

    $seeder->run();

    $resolvedGap = FrameworkGap::where('kind', 'competency_no_bars')
        ->where('role_code', 'FLL')
        ->where('competency_code', 'PRS')
        ->first();

    expect($resolvedGap)->not->toBeNull('gap row must still exist once resolved');
    expect($resolvedGap->status)->toBe('resolved');
    expect($resolvedGap->id)->toBe($originalId, 'resolution must reuse the SAME row, not insert a new one');
    expect($resolvedGap->created_at->toIso8601String())->toBe($originalCreatedAt, 'resolution must not touch created_at');

    // Remove the pair again (reopen) and re-seed — row identity must survive.
    $fllBarsReopened = json_decode(file_get_contents("{$barsDir}/FLL.json"), true, 512, JSON_THROW_ON_ERROR);
    unset($fllBarsReopened['PRS']);
    file_put_contents("{$barsDir}/FLL.json", json_encode($fllBarsReopened));

    $seeder->run();

    $reopenedGap = FrameworkGap::where('kind', 'competency_no_bars')
        ->where('role_code', 'FLL')
        ->where('competency_code', 'PRS')
        ->first();

    expect($reopenedGap)->not->toBeNull('gap row must still exist once reopened');
    expect($reopenedGap->status)->toBe('pending_authoring', 'a re-opened gap must flip back to pending_authoring');
    expect($reopenedGap->id)->toBe($originalId, 'reopen must reuse the SAME row, not insert a new one');
    expect($reopenedGap->created_at->toIso8601String())->toBe($originalCreatedAt, 'reopen must not touch created_at — this is the operational fact that distinguishes a reopen from a gap never before recorded');

    // Exactly one row must exist for this (kind, role_code, competency_code)
    // across the whole pending -> resolved -> pending cycle — the design's
    // entire justification for choosing status over delete.
    expect(
        FrameworkGap::where('kind', 'competency_no_bars')
            ->where('role_code', 'FLL')
            ->where('competency_code', 'PRS')
            ->count()
    )->toBe(1, 'the reopen must never leave two rows (one resolved, one fresh pending) for the same pair');

    cleanupGapResolutionFixtures($tmpDir);
});

test('competency_no_bars orphan gap resolves once roles.json no longer assigns the pair', function (): void {
    $tmpDir = sys_get_temp_dir().'/c3_gap_comp_orphan_'.uniqid();

    [$rolesFile, $competenciesFile, $barsDir] = buildGapResolutionFixtures($tmpDir);

    // FLL:PRS is fully anchored in the real catalogue as of
    // bars-catalogue-completion Phase 4 — see the sibling test above for why
    // the unanchored precondition is manufactured in the fixture copy only.
    $fllBarsInitial = json_decode(file_get_contents("{$barsDir}/FLL.json"), true, 512, JSON_THROW_ON_ERROR);
    unset($fllBarsInitial['PRS']);
    file_put_contents("{$barsDir}/FLL.json", json_encode($fllBarsInitial));

    $seeder = new FrameworkCatalogSeeder($rolesFile, $competenciesFile, $barsDir);
    $seeder->run();

    // FLL:PRS is now a currently-unanchored (but still currently-assigned) pair
    // in the fixture copy — see the fixture setup above.
    expect(
        FrameworkGap::where('kind', 'competency_no_bars')
            ->where('role_code', 'FLL')
            ->where('competency_code', 'PRS')
            ->where('status', 'pending_authoring')
            ->exists()
    )->toBeTrue('gap must start pending_authoring');

    // Remove the assignment from roles.json instead of authoring it — the gap
    // is now moot (the pair is no longer declared), not authored.
    $roles = json_decode(file_get_contents($rolesFile), true, 512, JSON_THROW_ON_ERROR);
    $roles['FLL']['competencies'] = array_values(
        array_filter($roles['FLL']['competencies'], fn ($c) => $c !== 'PRS')
    );
    file_put_contents($rolesFile, json_encode($roles));

    $seeder->run();

    expect(
        FrameworkGap::where('kind', 'competency_no_bars')
            ->where('role_code', 'FLL')
            ->where('competency_code', 'PRS')
            ->where('status', 'pending_authoring')
            ->exists()
    )->toBeFalse('orphaned gap must no longer be pending_authoring once the pair is unassigned');

    cleanupGapResolutionFixtures($tmpDir);
});
