<?php

declare(strict_types=1);

/**
 * The default closing phrase is seeded by a migration that never overwrites an admin edit, and its
 * down() removes the row only while it still equals the default.
 */

use App\Models\PlatformSetting;
use App\Support\Interview\PhraseNormalizer;
use App\Support\Settings\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function cpdMigration(): object
{
    $files = glob(database_path('migrations/*_seed_default_interview_closing_phrase.php'));
    expect($files)->toHaveCount(1);

    return require $files[0];
}

test('the default is seeded by the migration, in both locales, without any completion phrase', function (): void {
    $map = app(PlatformSettings::class)->interviewClosingPhraseMap();

    expect(array_keys($map))->toBe(['en', 'it']);

    foreach ($map as $text) {
        $normalised = PhraseNormalizer::normalize($text);

        foreach (PhraseNormalizer::completionPhrases() as $phrase) {
            expect($normalised)->not->toContain($phrase);
        }
    }
});

test('up() never overwrites an admin edit', function (): void {
    app(PlatformSettings::class)->setInterviewClosingPhrase(['en' => 'Custom.']);

    cpdMigration()->up();

    expect(app(PlatformSettings::class)->interviewClosingPhraseMap())->toBe(['en' => 'Custom.']);
});

test('up() inserts the default when the row is missing', function (): void {
    PlatformSetting::query()->whereKey(PlatformSettings::INTERVIEW_CLOSING_KEY)->delete();

    cpdMigration()->up();

    expect(app(PlatformSettings::class)->interviewClosingPhraseMap())->toHaveKeys(['en', 'it']);
});

test('down() removes the row only while it is still the default', function (): void {
    $migration = cpdMigration();
    $migration->down();
    expect(PlatformSetting::query()->whereKey(PlatformSettings::INTERVIEW_CLOSING_KEY)->exists())->toBeFalse();

    app(PlatformSettings::class)->setInterviewClosingPhrase(['en' => 'Custom.']);
    $migration->down();

    expect(app(PlatformSettings::class)->interviewClosingPhraseMap())->toBe(['en' => 'Custom.']);
});
