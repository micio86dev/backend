<?php

declare(strict_types=1);

/**
 * MTG and LAT — the `potential` catalogue
 * (potential-competencies-and-authored-questions).
 *
 * `assessment_type: potential` has never been usable. Every attempt was
 * refused with POTENTIAL_CATALOG_INCOMPLETE because
 * `Competency::whereIn('code', ['MTG','LAT'])->count()` was 0, and the seeder
 * recorded both as `pending_authoring` on every single run without ever
 * checking whether they existed.
 *
 * The blocker was structural, not editorial:
 * `framework_bars_indicators.role_id` was NOT NULL, and a potential project
 * has `role_code = null` by rule — so there was nowhere to put an indicator
 * for either competency. Authoring the text alone would have made the project
 * creatable and then unscoreable, which is strictly worse than refusing it.
 */

use App\Models\BarsIndicator;
use App\Models\Competency;
use App\Models\FrameworkGap;
use Database\Seeders\FrameworkCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(FrameworkCatalogSeeder::class);
});

test('MTG and LAT exist and are typed potential', function (): void {
    // `type` decides whether StoreProjectRequest accepts them: a potential
    // project requires every competency to be type=potential, and the seeder
    // used to stamp `standard` on everything it read from competencies.json.
    $codes = Competency::whereIn('code', ['MTG', 'LAT'])->pluck('type', 'code');

    expect($codes)->toHaveCount(2)
        ->and($codes['MTG'])->toBe('potential')
        ->and($codes['LAT'])->toBe('potential');
});

test('both are authored in English AND Italian', function (): void {
    // i18n is mandatory it/en, and a competency whose Italian is missing
    // produces an interview that switches language mid-assessment.
    // Read through getAttributes(), NOT `$c->name`: the model resolves an
    // accessor to the CURRENT locale, so `$c->name` is the string "Managing"
    // rather than the locale map. Asserting on the accessor would test the
    // request locale and pass with an empty `it`.
    foreach (['MTG', 'LAT'] as $code) {
        $c = Competency::where('code', $code)->firstOrFail();
        $raw = $c->getAttributes();
        $name = json_decode((string) $raw['name'], true);
        $definition = json_decode((string) $raw['definition'], true);

        expect($name['en'] ?? null)->not->toBeEmpty()
            ->and($name['it'] ?? null)->not->toBeEmpty()
            ->and($definition['en'] ?? null)->not->toBeEmpty()
            ->and($definition['it'] ?? null)->not->toBeEmpty();
    }
});

test('both carry role-less BARS indicators with all three anchors', function (): void {
    // The indicators ARE the scoring instrument. Without them a potential
    // project would be creatable and then fail to score.
    foreach (['MTG', 'LAT'] as $code) {
        $competency = Competency::where('code', $code)->firstOrFail();

        $indicators = BarsIndicator::whereNull('role_id')
            ->where('competency_id', $competency->id)
            ->orderBy('position')
            ->get();

        expect($indicators)->not->toBeEmpty();

        // Same reason as above — the raw column, not the locale-resolving
        // accessor. An anchor missing its Italian makes an `it` interview
        // unscoreable, and the accessor would hide exactly that.
        foreach ($indicators as $i) {
            $raw = $i->getAttributes();

            foreach (['text', 'anchor_5', 'anchor_3', 'anchor_1'] as $column) {
                $map = json_decode((string) $raw[$column], true);

                expect($map['en'] ?? null)->not->toBeEmpty()
                    ->and($map['it'] ?? null)->not->toBeEmpty();
            }
        }
    }
});

test('the pending_authoring gap is RESOLVED, not re-recorded every run', function (): void {
    // It was written unconditionally — the seeder never asked whether the
    // competency it was reporting missing had since been authored.
    $pending = FrameworkGap::where('kind', 'missing_potential_competency')
        ->where('status', 'pending_authoring')
        ->pluck('competency_code');

    expect($pending)->toBeEmpty();
});

test('the catalogue is now complete enough to create a potential project', function (): void {
    // The exact predicate StoreProjectRequest::validatePotential() uses.
    expect(Competency::whereIn('code', ['MTG', 'LAT'])->count())->toBe(2);
});
