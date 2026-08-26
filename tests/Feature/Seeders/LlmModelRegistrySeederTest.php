<?php

declare(strict_types=1);

/**
 * LlmModelRegistrySeeder — idempotency and mark-stale-never-delete
 * (pluggable-conversation-llm PR P1, design D1).
 *
 * REQ: conversation-llm "The model registry is global, upserted, and carries
 *      a per-request context pricing tier"; "Registry sync runs via a
 *      console command, never db:seed"
 */

use App\Models\LlmModel;
use Database\Seeders\LlmModelRegistrySeeder;

test('the seeder produces exactly the four verified model keys and no others', function (): void {
    (new LlmModelRegistrySeeder)->run();

    $keys = LlmModel::pluck('key')->all();

    expect($keys)->toHaveCount(4);
    expect($keys)->toEqualCanonicalizing([
        'gemini-3-flash-preview',
        'gemini-3.1-pro-preview',
        'gemini-3.1-flash-live-preview',
        'gemini-2.5-flash-native-audio-preview-12-2025',
    ]);

    // The two ids the brief got wrong (design.md C-A) must never appear.
    expect($keys)->not->toContain('gemini-3-pro');
    expect($keys)->not->toContain('gemini-3-flash');
});

test('gemini-3.1-pro-preview carries the 200k context tier and a genuinely NULL audio rate', function (): void {
    (new LlmModelRegistrySeeder)->run();

    $model = LlmModel::where('key', 'gemini-3.1-pro-preview')->firstOrFail();

    expect($model->context_tier_threshold_tokens)->toBe(200000);
    expect((string) $model->text_input_usd_per_million_high)->toBe('4.000000');
    expect((string) $model->text_output_usd_per_million_high)->toBe('18.000000');
    expect($model->audio_input_usd_per_million)->toBeNull();
});

test('running the seeder twice yields an identical row set (idempotent upsert on key)', function (): void {
    (new LlmModelRegistrySeeder)->run();
    $firstRun = LlmModel::orderBy('key')->get(['id', 'key', 'updated_at'])->toArray();

    (new LlmModelRegistrySeeder)->run();
    $secondRun = LlmModel::orderBy('key')->get(['id', 'key', 'updated_at'])->toArray();

    // Same ids, same keys, same updated_at — an upsert on the natural key,
    // not a delete-then-recreate that would silently break every FK pointing
    // at the old row id.
    expect($secondRun)->toBe($firstRun);
});

test('a model removed from the seed array becomes unavailable, never deleted, and keeps its display name', function (): void {
    $full = require database_path('seeders/data/llm_models.php');
    (new LlmModelRegistrySeeder($full))->run();

    $removedKey = 'gemini-3.1-flash-live-preview';
    $original = LlmModel::where('key', $removedKey)->firstOrFail();

    $withoutOne = array_values(array_filter($full, fn (array $row): bool => $row['key'] !== $removedKey));
    (new LlmModelRegistrySeeder($withoutOne))->run();

    $stillThere = LlmModel::where('key', $removedKey)->first();

    expect($stillThere)->not->toBeNull('a model absent from the seed array must NEVER be deleted');
    expect($stillThere->id)->toBe($original->id);
    expect($stillThere->display_name)->toBe($original->display_name);
    expect($stillThere->is_available)->toBeFalse();

    // The other three, still present in the array, remain available.
    expect(LlmModel::where('is_available', true)->count())->toBe(3);
});
