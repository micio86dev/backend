<?php

/**
 * RED — 4.4: Translatable locale resolution and gap detection (C3).
 *
 * (a) Both EN and IT set → returns active locale value.
 * (b) Only EN set (no IT set) → falls back to EN; hasTranslation('name','it') is false.
 * (c) hasTranslation-based gap detection validates the spec's approach
 *     (NOT an empty/null check — empty-string IT would return true from hasTranslation).
 *
 * Refs spec: "Requesting IT locale when IT translation is absent falls back to EN".
 */

use App\Models\Competency;
use Illuminate\Support\Facades\App;

test('competency returns active locale when both en and it translations exist', function (): void {
    $competency = new Competency;
    $competency->setTranslation('name', 'en', 'Problem Solving');
    $competency->setTranslation('name', 'it', 'Risoluzione dei problemi');
    $competency->setTranslation('definition', 'en', 'Definition in EN');
    $competency->setTranslation('definition', 'it', 'Definizione in IT');
    $competency->code = 'TEST';
    $competency->type = 'standard';
    $competency->save();

    App::setLocale('it');
    expect($competency->fresh()->name)->toBe('Risoluzione dei problemi');

    App::setLocale('en');
    expect($competency->fresh()->name)->toBe('Problem Solving');
});

test('competency falls back to en when it translation is absent', function (): void {
    $competency = new Competency;
    $competency->setTranslation('name', 'en', 'Strategy');
    $competency->setTranslation('definition', 'en', 'Definition EN');
    $competency->code = 'TEST2';
    $competency->type = 'standard';
    $competency->save();

    // Ensure IT is NOT set
    App::setLocale('it');
    $fresh = $competency->fresh();

    // Should fall back to EN content
    expect($fresh->name)->toBe('Strategy');

    // hasTranslation must return false — this is how gap detection works per spec
    expect($fresh->hasTranslation('name', 'it'))->toBeFalse();
});

test('hasTranslation returns true only when translation was explicitly set', function (): void {
    $competency = new Competency;
    $competency->setTranslation('name', 'en', 'Innovation');
    $competency->setTranslation('definition', 'en', 'Def EN');
    $competency->code = 'TEST3';
    $competency->type = 'standard';
    $competency->save();

    $fresh = $competency->fresh();

    // No IT set → hasTranslation false
    expect($fresh->hasTranslation('name', 'it'))->toBeFalse();

    // Now set IT
    $fresh->setTranslation('name', 'it', 'Innovazione');
    $fresh->save();

    expect($fresh->fresh()->hasTranslation('name', 'it'))->toBeTrue();
});

test('empty string IT is treated as absent by spatie (allowEmptyStringForTranslation=false by default)', function (): void {
    // Spatie v6+ with allowEmptyStringForTranslation=false (default):
    // an empty-string translation set via setTranslation is filtered OUT by getTranslations().
    // As a result, hasTranslation('name','it') returns false for empty-string IT,
    // meaning gap detection correctly identifies it as missing.
    // The seeder never sets empty strings; it uses the actual JSON value or skips.
    // This test documents the actual library behavior, which is more conservative than
    // the spec note suggested.
    $competency = new Competency;
    $competency->setTranslation('name', 'en', 'Drive');
    $competency->setTranslation('name', 'it', ''); // empty string IT
    $competency->setTranslation('definition', 'en', 'Def EN');
    $competency->code = 'TEST4';
    $competency->type = 'standard';
    $competency->save();

    $fresh = $competency->fresh();

    // With allowEmptyStringForTranslation=false (default), spatie filters empty strings.
    // hasTranslation returns false for empty-string IT (treated same as absent).
    expect($fresh->hasTranslation('name', 'it'))->toBeFalse();
});
