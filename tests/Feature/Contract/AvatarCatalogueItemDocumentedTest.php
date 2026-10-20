<?php

declare(strict_types=1);

/**
 * The published catalogue contract must type its items (hr-avatar-adapter A8).
 *
 * Scramble cannot infer the item shape through `AvatarProviderCatalogue::entry()` and used to document
 * `items` as `unknown[]`, which forced the backoffice to hand-write `CatalogueEntry`. The COMMITTED
 * `openapi.json` is read the way the SDK codegen reads it, and real catalogue entries are checked against
 * that schema so the annotation cannot drift from what the proxy returns.
 */

use App\Support\AvatarTemplates\AvatarProviderCatalogue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, mixed>
 */
function catalogueItemSchema(): array
{
    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    return $spec['paths']['/avatar-templates/catalogue']['get']['responses']['200']['content']['application/json']['schema']['properties']['data']['properties']['items']['items'];
}

/**
 * @param  array<string, mixed>  $item
 */
function catalogueItemMatches(array $item, array $schema): bool
{
    foreach ($item as $key => $value) {
        $types = (array) ($schema['properties'][$key]['type'] ?? []);
        $actual = match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_string($value) => 'string',
            default => 'other',
        };

        if (! in_array($actual, $types, true)) {
            return false;
        }
    }

    return array_diff($schema['required'], array_keys($item)) === [];
}

test('the catalogue documents a typed item, with editable the only optional member', function (): void {
    $schema = catalogueItemSchema();

    expect($schema['type'])->toBe('object')
        ->and(array_keys($schema['properties']))->toBe([
            'id', 'provider', 'label', 'name', 'language', 'locale', 'accent', 'italian',
            'preview_image_url', 'preview_audio_url', 'preview_audio_via_api', 'preview_video_url', 'editable',
        ])
        ->and($schema['required'])->toBe([
            'id', 'provider', 'label', 'name', 'language', 'locale', 'accent', 'italian',
            'preview_image_url', 'preview_audio_url', 'preview_audio_via_api', 'preview_video_url',
        ])
        ->and($schema['properties']['preview_audio_via_api']['type'])->toBe('boolean')
        ->and($schema['properties']['language']['type'])->toBe(['string', 'null'])
        ->and($schema['properties']['editable']['type'])->toBe(['boolean', 'null']);
});

test('real catalogue entries validate against the documented item', function (): void {
    config(['interview.tavus.api_key' => 'tavus-test-key']);
    hraMakeAvailable();
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake([
        '*persona_type=*' => Http::response(['data' => [['pal_id' => 'p1', 'pal_name' => 'Persona']], 'total_count' => 1]),
        'tavusapi.com/v2/pals*' => Http::response(['data' => [['pal_id' => 'p1', 'pal_name' => 'Persona']], 'total_count' => 1]),
        '*/v1/avatar-configs*' => Http::response(['data' => [['id' => 'avc_1', 'name' => 'Config', 'external_ref' => 'org_7', 'sync_status' => 'synced']], 'next_cursor' => null]),
    ]);
    $schema = catalogueItemSchema();

    $pal = AvatarProviderCatalogue::fetch('tavus', 'pal', true)['items'][0];
    $config = AvatarProviderCatalogue::fetch('hr_avatar', 'avatar_config', true, 7)['items'][0];

    expect($pal)->toHaveKey('editable')
        ->and($config)->not->toHaveKey('editable')
        ->and(catalogueItemMatches($pal, $schema))->toBeTrue()
        ->and(catalogueItemMatches($config, $schema))->toBeTrue();
});
