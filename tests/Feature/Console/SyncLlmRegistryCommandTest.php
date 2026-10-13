<?php

declare(strict_types=1);

use App\Enums\LlmCapability;
use App\Models\LlmModel;
use Database\Seeders\LlmModelRegistrySeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * @return array<string, mixed>
 */
function registryRow(string $key, string $name): array
{
    return [
        'key' => $key,
        'vendor' => 'google',
        'display_name' => $name,
        'base_url' => 'https://example.test/v1/',
        'capability' => LlmCapability::Text->value,
        'sort_order' => 10,
    ];
}

function syncRegistryWith(array $rows): string
{
    app()->bind(LlmModelRegistrySeeder::class, fn () => new LlmModelRegistrySeeder($rows));
    expect(Artisan::call('beai:sync-llm-registry'))->toBe(0);

    return Artisan::output();
}

test('the first sync reports each model as added', function (): void {
    $output = syncRegistryWith([registryRow('m-a', 'A'), registryRow('m-b', 'B')]);

    expect($output)->toContain('added: m-a')->toContain('added: m-b')->not->toContain('No changes');
    expect(LlmModel::pluck('key')->all())->toEqualCanonicalizing(['m-a', 'm-b']);
});

test('a re-sync reports existing rows as updated and rows dropped from the catalog as marked unavailable, never deleted', function (): void {
    syncRegistryWith([registryRow('m-a', 'A'), registryRow('m-b', 'B')]);

    $output = syncRegistryWith([registryRow('m-a', 'A renamed')]);

    expect($output)->toContain('updated: m-a')->toContain('marked unavailable: m-b');
    expect(LlmModel::where('key', 'm-a')->value('display_name'))->toBe('A renamed');
    expect(LlmModel::where('key', 'm-b')->value('is_available'))->toBeFalse();
});

test('syncing an empty catalog against an empty registry reports no changes', function (): void {
    expect(syncRegistryWith([]))->toContain('No changes');
});
