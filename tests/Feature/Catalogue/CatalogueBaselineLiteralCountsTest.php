<?php

declare(strict_types=1);

/**
 * RED/GREEN — 10.2/11.4 (framework-catalogue-authoring PR3, D3): 83 role×
 * competency pairs (15/18/18/14/18) and 85 anchored competencies, asserted
 * against the BASELINE revision only, against the database — never
 * re-derived from the source JSON, and never enforced on every publish
 * (Contradiction 2: a literal count on every publish would refuse the first
 * competency a superadmin ever adds).
 */

use App\Models\FrameworkCatalogRevision;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\DB;

test('the baseline carries exactly 83 role×competency pairs and 85 anchored competencies', function (): void {
    (new FrameworkCatalogSeeder)->run();

    $baseline = FrameworkCatalogRevision::where('is_baseline', true)->firstOrFail();

    $pairCount = DB::table('framework_role_competency')->where('revision_id', $baseline->id)->count();
    expect($pairCount)->toBe(83);

    $perRole = DB::table('framework_role_competency as prc')
        ->join('framework_roles as r', 'r.id', '=', 'prc.role_id')
        ->where('prc.revision_id', $baseline->id)
        ->selectRaw('r.code, count(*) as total')
        ->groupBy('r.code')
        ->pluck('total', 'code');

    expect($perRole['ICO'])->toBe(15);
    expect($perRole['FLL'])->toBe(18);
    expect($perRole['MLL'])->toBe(18);
    expect($perRole['BUL'])->toBe(14);
    expect($perRole['SRX'])->toBe(18);

    // 85 "anchored competencies" (CLAUDE.md) = 83 role-scoped (role_id,
    // competency_id) pairs PLUS MTG and LAT (potential-only, no role) — a
    // distinct (role_id, competency_id) COMBINATION count, never a distinct
    // competency_id count: standard competency CODES repeat across roles
    // (e.g. the same "COM" is anchored under multiple roles), so counting
    // distinct competency_id alone would undercount badly.
    $anchoredPairCount = DB::table('framework_bars_indicators')
        ->where('revision_id', $baseline->id)
        ->distinct()
        ->get(['role_id', 'competency_id'])
        ->count();

    expect($anchoredPairCount)->toBe(85);
});
