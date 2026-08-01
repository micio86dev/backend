<?php

/**
 * RED — 8.3: Seeded-count correctness (C3).
 *
 * After seeder run: asserts exact role/competency counts,
 * per-role pivot counts, and per-role bars_indicators counts.
 *
 * Refs spec: "First run seeds roles and competencies from JSON" + "Seeded-count correctness".
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\Role;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
});

test('seeder creates exactly 5 roles and 18 competencies', function (): void {
    expect(Role::count())->toBe(5)
        ->and(Competency::count())->toBe(18);
});

test('per-role pivot counts match JSON definitions', function (): void {
    $counts = [
        'ICO' => 15,
        'FLL' => 18,
        'MLL' => 18,
        'BUL' => 14,
        'SRX' => 18,
    ];

    foreach ($counts as $code => $expected) {
        $role = Role::where('code', $code)->first();
        $actual = DB::table('framework_role_competency')
            ->where('role_id', $role->id)
            ->count();
        expect($actual)->toBe($expected, "Expected {$code} pivot count = {$expected}, got {$actual}");
    }
});

test('per-role BARS-covered competency counts match design', function (): void {
    $covered = [
        'ICO' => 15,
        'FLL' => 8,
        'MLL' => 8,
        'BUL' => 8,
        'SRX' => 0,
    ];

    foreach ($covered as $code => $expected) {
        $role = Role::where('code', $code)->first();
        $actual = BarsIndicator::where('role_id', $role->id)
            ->distinct()
            ->count('competency_id');
        expect($actual)->toBe($expected, "Expected {$code} BARS-covered count = {$expected}, got {$actual}");
    }
});

test('total bars_indicators rows per role match design (3 indicators per covered competency)', function (): void {
    $barsRows = [
        'ICO' => 45, // 15 × 3
        'FLL' => 24, // 8 × 3
        'MLL' => 24, // 8 × 3
        'BUL' => 24, // 8 × 3
        'SRX' => 0,
    ];

    foreach ($barsRows as $code => $expected) {
        $role = Role::where('code', $code)->first();
        $actual = BarsIndicator::where('role_id', $role->id)->count();
        expect($actual)->toBe($expected, "Expected {$code} bars_indicators total = {$expected}, got {$actual}");
    }
});
