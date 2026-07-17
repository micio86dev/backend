<?php

/**
 * RED — 8.8: Re-seeding preserves manually-added IT translations (C3).
 *
 * Seeds EN, manually adds IT to a Competency, re-seeds, asserts IT survives
 * and EN reflects the JSON value.
 *
 * Refs spec: "Re-seeding preserves manually-added IT translations".
 */

use App\Models\Competency;
use Database\Seeders\FrameworkCatalogSeeder;

test('re-seeding preserves manually-added IT translation on a competency', function (): void {
    $seeder = new FrameworkCatalogSeeder();
    $seeder->run();

    // Manually add an IT translation after the first seed
    $competency = Competency::where('code', 'PRS')->first();
    $competency->setTranslation('name', 'it', 'Risoluzione dei problemi');
    $competency->save();

    expect($competency->fresh()->hasTranslation('name', 'it'))->toBeTrue();
    expect($competency->fresh()->getTranslation('name', 'it'))->toBe('Risoluzione dei problemi');

    // Re-seed — should NOT overwrite IT
    $seeder->run();

    $fresh = Competency::where('code', 'PRS')->first();

    // IT translation must still be present
    expect($fresh->hasTranslation('name', 'it'))->toBeTrue();
    expect($fresh->getTranslation('name', 'it'))->toBe('Risoluzione dei problemi');

    // EN must still reflect JSON value (Problem Solving)
    expect($fresh->getTranslation('name', 'en'))->toBe('Problem Solving');
});
