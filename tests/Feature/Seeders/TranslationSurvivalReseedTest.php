<?php

/**
 * Re-seeding preserves a manually-added translation for a locale the SOURCE
 * does not carry (C3).
 *
 * This is not a nicety — it is the reason `framework:forget-locale` exists.
 * `setTranslation` MERGES into the JSON column rather than replacing it, so
 * deleting a locale from the catalogue source and re-seeding will never remove
 * it from the database. A test that proves the merge is what makes that
 * asymmetry a documented property instead of a surprise.
 *
 * History, and the reason this file was rewritten: the original version added
 * an `it` translation to PRS and asserted it survived a re-seed. That worked
 * only while the catalogue source had no Italian. Once
 * framework-catalog-it-translations authored all 1042 strings, the source
 * DID carry `it` for PRS, the seeder correctly wrote the source value over the
 * manual one, and the test failed — not because the merge broke, but because
 * its precondition had been borrowed from the real catalogue rather than
 * manufactured. This is the same defect class this codebase has now hit eight
 * times: a test that depends on the data being incomplete stops testing the
 * moment you complete it.
 *
 * So the survival case now uses `fr`, a locale the catalogue genuinely does
 * not author and is not planned to. That is the real condition under test, and
 * it cannot be invalidated by translating more of the catalogue.
 */

use App\Models\Competency;
use Database\Seeders\FrameworkCatalogSeeder;

test('re-seeding preserves a manually-added translation for a locale absent from the source', function (): void {
    $seeder = new FrameworkCatalogSeeder;
    $seeder->run();

    $competency = Competency::where('code', 'PRS')->firstOrFail();

    // Precondition, asserted rather than assumed: the source must NOT carry
    // `fr`. If the catalogue ever gains French, this assertion fails loudly and
    // whoever adds it gets to choose a new absent locale — instead of the test
    // quietly going vacuous, which is exactly what happened to its predecessor.
    expect($competency->hasTranslation('name', 'fr'))->toBeFalse(
        'precondition: the catalogue source must not author `fr` for this test to mean anything'
    );

    $competency->setTranslation('name', 'fr', 'Résolution de problèmes');
    $competency->save();

    $seeder->run();

    $fresh = Competency::where('code', 'PRS')->firstOrFail();

    expect($fresh->getTranslation('name', 'fr'))->toBe('Résolution de problèmes');
    expect($fresh->getTranslation('name', 'en'))->toBe('Problem Solving');
});

test('re-seeding overwrites a manual translation for a locale the source DOES author', function (): void {
    $seeder = new FrameworkCatalogSeeder;
    $seeder->run();

    $competency = Competency::where('code', 'PRS')->firstOrFail();
    $sourceItalian = $competency->getTranslation('name', 'it');

    expect($sourceItalian)->not->toBe('');

    // A hand edit in the database is not a source of truth. The catalogue JSON
    // is, and a re-seed must restore it — otherwise a stray production edit
    // would silently outrank the reviewed, committed content forever.
    $competency->setTranslation('name', 'it', 'Valore inventato a mano');
    $competency->save();

    $seeder->run();

    expect(Competency::where('code', 'PRS')->firstOrFail()->getTranslation('name', 'it'))
        ->toBe($sourceItalian);
});
