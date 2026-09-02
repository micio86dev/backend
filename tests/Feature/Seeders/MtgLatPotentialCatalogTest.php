<?php

/**
 * MTG/LAT — the potential catalogue, now authored.
 *
 * This file was `MtgLatAbsentGapTest` and asserted the opposite: that neither
 * competency existed and that both carried a `pending_authoring` gap. That was
 * a correct description of C3, where the definitions had not been written and
 * `framework_bars_indicators.role_id` was NOT NULL so they could not have been
 * stored anyway.
 *
 * Both facts changed (potential-competencies-and-authored-questions). The
 * assertions are inverted rather than deleted, because the file's job is
 * unchanged — it is the guard on the state of the potential catalogue, and
 * that state now has to be "present and complete" instead of "absent and
 * flagged".
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\FrameworkGap;
use Database\Seeders\FrameworkCatalogSeeder;

beforeEach(function (): void {
    (new FrameworkCatalogSeeder)->run();
});

test('MTG is created, typed potential', function (): void {
    $c = Competency::where('code', 'MTG')->first();

    expect($c)->not->toBeNull()
        ->and($c->type)->toBe('potential');
});

test('LAT is created, typed potential', function (): void {
    $c = Competency::where('code', 'LAT')->first();

    expect($c)->not->toBeNull()
        ->and($c->type)->toBe('potential');
});

test('neither is assigned to a role', function (): void {
    // Potential competencies belong to no role — that is the whole reason
    // `role_id` had to become nullable. If one appeared in the pivot, a
    // `standard` project for that role could select it, and the exclusivity
    // rule ("standard and potential cannot be mixed") would be broken at the
    // catalogue level rather than at validation.
    foreach (['MTG', 'LAT'] as $code) {
        $competency = Competency::where('code', $code)->firstOrFail();

        expect($competency->roles()->count())->toBe(0);
    }
});

test('both carry role-less BARS indicators', function (): void {
    foreach (['MTG', 'LAT'] as $code) {
        $competency = Competency::where('code', $code)->firstOrFail();

        expect(
            BarsIndicator::whereNull('role_id')->where('competency_id', $competency->id)->count()
        )->toBeGreaterThan(0);
    }
});

test('the pending_authoring gap is resolved for both', function (): void {
    // The gap row is kept and marked resolved rather than deleted: it is the
    // record that this was once missing, and the seeder previously rewrote it
    // as pending on EVERY run without asking whether it still applied.
    foreach (['MTG', 'LAT'] as $code) {
        expect(
            FrameworkGap::where('kind', 'missing_potential_competency')
                ->where('competency_code', $code)
                ->where('status', 'pending_authoring')
                ->exists()
        )->toBeFalse();
    }
});
