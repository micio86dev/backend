<?php

declare(strict_types=1);

/**
 * The published export contract must match what the export really emits (hr-avatar-adapter A8).
 *
 * `TemplateDocument::export` omits `hr_avatar_config_id` while a template has none, so a document
 * for an unlinked template is byte-for-byte what it was before the member existed. The COMMITTED
 * `openapi.json` is read the way the SDK codegen reads it: the key must be documented as a string
 * and NOT required, or a generated client rejects every pre-existing document.
 */

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\AvatarTemplates\TemplateActors;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function hraExportEntrySchema(): array
{
    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    return $spec['paths']['/avatar-templates/export']['get']['responses']['200']['content']['application/json']['schema']['properties']['templates']['items'];
}

test('the export schema documents hr_avatar_config_id as an optional string', function (): void {
    $schema = hraExportEntrySchema();

    expect($schema['properties']['hr_avatar_config_id']['type'])->toBe('string')
        ->and($schema['required'])->not->toContain('hr_avatar_config_id');
});

test('every member the export always emits is required, and every required member is emitted for an unlinked template', function (): void {
    Http::fake();
    Http::preventStrayRequests();
    $org = Organization::factory()->create();
    hraSeedTemplate($org, ['provider' => 'tavus', 'config' => ['replicaId' => 'r'], 'hr_avatar_config_id' => null]);

    $entry = test()->withToken(TemplateActors::token('acting', $org))->getJson('/api/avatar-templates/export')
        ->assertOk()->json('templates.0');
    $schema = hraExportEntrySchema();

    expect($schema['required'])->toEqualCanonicalizing(array_keys($entry))
        ->and(array_keys($schema['properties']))->toContain(...array_keys($entry));
});
