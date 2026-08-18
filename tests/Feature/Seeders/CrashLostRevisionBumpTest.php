<?php

/**
 * RED — Post-verification hardening finding #1 (C3): a structural change
 * committed before a mid-run crash must never leave CatalogMeta::revision
 * permanently stale.
 *
 * FrameworkCatalogSeeder::run() had no transactional boundary. Every
 * destructive/insert statement (role/competency/pivot/BarsIndicator writes)
 * committed as it executed, while CatalogMeta::bump() was gated on an
 * IN-MEMORY $structuralChange flag computed across the whole method. If the
 * method threw partway through (a malformed bars/{ROLE}.json is the natural
 * lever — json_decode(..., JSON_THROW_ON_ERROR) throws \JsonException), every
 * row processed before the throw stayed committed, but bump() was never
 * reached. On a clean retry, every structurally-new row from that partial
 * run ALREADY EXISTS, so nothing looks newly created THIS run, so
 * $structuralChange stays false, so bump() is skipped again — forever.
 *
 * This test reproduces that exact sequence:
 *   1. Seed a baseline catalog successfully (captures revision R0).
 *   2. Add a genuinely NEW BarsIndicator to ICO (the FIRST role processed)
 *      and corrupt bars/SRX.json (the LAST role processed) so the run
 *      commits ICO's new row, then throws before ever reaching bump().
 *   3. Fix the malformed file (a clean retry) and run again — this attempt
 *      creates nothing new anywhere, because everything (including ICO's
 *      extra indicator from step 2) already exists.
 *
 * Between step 1 and step 3 the catalog UNDENIABLY changed structurally
 * (one new BarsIndicator row). The revision must reflect that.
 */

use App\Models\BarsIndicator;
use App\Models\CatalogMeta;
use App\Models\Competency;
use App\Models\Role;
use Database\Seeders\FrameworkCatalogSeeder;

test('a structural change committed before a mid-run crash is not permanently lost from the catalog revision', function (): void {
    $sourceBase = dirname(base_path()).'/docs/app_description/02-domain/framework';
    $tempBase = sys_get_temp_dir().'/c3_crash_revision_test_'.uniqid();
    mkdir("{$tempBase}/bars", 0755, true);
    copy("{$sourceBase}/roles.json", "{$tempBase}/roles.json");
    copy("{$sourceBase}/competencies.json", "{$tempBase}/competencies.json");
    // Processing order follows roles.json key order: ICO, FLL, MLL, BUL, SRX.
    // ICO is FIRST (its new indicator commits early); SRX is LAST (the crash
    // point) — the malformed file must be the one processed last, so every
    // other role's structural work has already landed before the throw.
    foreach (['ICO', 'FLL', 'MLL', 'BUL', 'SRX'] as $role) {
        copy("{$sourceBase}/bars/{$role}.json", "{$tempBase}/bars/{$role}.json");
    }

    // ── 1. Baseline: one normal, successful run ────────────────────────
    (new FrameworkCatalogSeeder(
        rolesFile: "{$tempBase}/roles.json",
        competenciesFile: "{$tempBase}/competencies.json",
        barsDir: "{$tempBase}/bars",
    ))->run();

    $baselineRevision = CatalogMeta::first()->revision;
    expect($baselineRevision)->toBeGreaterThan(0);

    $icoRole = Role::where('code', 'ICO')->firstOrFail();
    $prsCompetency = Competency::where('code', 'PRS')->firstOrFail();
    $baselinePrsCount = BarsIndicator::where('role_id', $icoRole->id)
        ->where('competency_id', $prsCompetency->id)
        ->count();

    // ── 2. A genuinely NEW indicator for ICO/PRS (processed first) ─────
    $icoBars = json_decode(file_get_contents("{$tempBase}/bars/ICO.json"), true, 512, JSON_THROW_ON_ERROR);
    $icoBars['PRS'][] = [
        'indicator' => 'A brand-new indicator added after the baseline catalog was seeded.',
        'scale' => [
            '5' => 'Five-level anchor for the new indicator.',
            '3' => 'Three-level anchor for the new indicator.',
            '1' => 'One-level anchor for the new indicator.',
        ],
    ];
    file_put_contents(
        "{$tempBase}/bars/ICO.json",
        json_encode($icoBars, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    );

    // Corrupt SRX.json (processed LAST) so the run crashes AFTER ICO's new
    // row is committed but BEFORE CatalogMeta::bump() is ever reached.
    file_put_contents("{$tempBase}/bars/SRX.json", '{ this is not valid json');

    $crashSeeder = new FrameworkCatalogSeeder(
        rolesFile: "{$tempBase}/roles.json",
        competenciesFile: "{$tempBase}/competencies.json",
        barsDir: "{$tempBase}/bars",
    );

    expect(fn () => $crashSeeder->run())->toThrow(JsonException::class);

    // ── 3. Clean retry: fix the malformed file, run again ──────────────
    copy("{$sourceBase}/bars/SRX.json", "{$tempBase}/bars/SRX.json");

    $retrySeeder = new FrameworkCatalogSeeder(
        rolesFile: "{$tempBase}/roles.json",
        competenciesFile: "{$tempBase}/competencies.json",
        barsDir: "{$tempBase}/bars",
    );

    expect(fn () => $retrySeeder->run())->not->toThrow(Exception::class);

    $retryPrsCount = BarsIndicator::where('role_id', $icoRole->id)
        ->where('competency_id', $prsCompetency->id)
        ->count();

    // The structural change is real: one extra ICO/PRS indicator now
    // exists, and it did NOT get created by the retry (the retry created
    // nothing new anywhere — that is exactly the bug's premise).
    expect($retryPrsCount)->toBe($baselinePrsCount + 1);

    $retryRevision = CatalogMeta::first()->fresh()->revision;

    // THE ASSERTION THAT FAILS ON CURRENT CODE: a structural change was
    // committed to the catalog between the baseline and the retry, so the
    // revision must have moved — even though the retry itself inserted
    // nothing new.
    expect($retryRevision)->toBeGreaterThan($baselineRevision);

    // Cleanup
    array_map('unlink', glob("{$tempBase}/bars/*.json") ?: []);
    unlink("{$tempBase}/roles.json");
    unlink("{$tempBase}/competencies.json");
    rmdir("{$tempBase}/bars");
    rmdir($tempBase);
});
