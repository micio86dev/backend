<?php

declare(strict_types=1);

use App\Models\PlatformSetting;
use App\Support\Settings\PlatformSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

test('setMaxQuestionsPerCompetency locks the settings row before reading it', function (): void {
    PlatformSetting::query()->whereKey(PlatformSettings::MAX_QUESTIONS_KEY)->delete();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    app(PlatformSettings::class)->setMaxQuestionsPerCompetency(['standard' => 2]);

    $lockIndex = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'for update'));
    $writeIndex = collect($queries)->search(fn (string $sql): bool => str_starts_with($sql, 'update "platform_settings"'));

    expect($lockIndex)->not->toBeFalse()
        ->and($lockIndex)->toBeLessThan($writeIndex);
});

test('sequential partial updates merge instead of overwriting each other', function (): void {
    $settings = app(PlatformSettings::class);

    $settings->setMaxQuestionsPerCompetency(['standard' => 3]);
    $merged = $settings->setMaxQuestionsPerCompetency(['potential' => 5]);

    expect($merged)->toBe(['standard' => 3, 'potential' => 5])
        ->and($settings->maxQuestionsPerCompetencyMap())->toBe(['standard' => 3, 'potential' => 5]);
});
