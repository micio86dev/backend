<?php

declare(strict_types=1);

use App\Models\Organization;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\AvatarTemplates\TemplateActors;

// Generating the document introspects request classes whose rules() can open a draft catalogue revision;
// roll it back so a serial run does not leak it (see VoicePreviewTransformerTest).
uses(RefreshDatabase::class);

/**
 * Both field-spec routes answer `{data: {heygen: [FieldSpec...], tavus: [FieldSpec...]}}`, but Scramble infers
 * `{data: string}` from the helper-built array. The documented 200 must be the real shape, so a generated client
 * types the form's spec list instead of a string.
 */
dataset('field spec routes', [
    'organization route' => ['/avatar-templates/field-specs'],
    'platform route' => ['/admin/avatar-templates/field-specs'],
]);

function generatedFieldSpecsBody(string $path): array
{
    static $document = null;
    $document ??= app(Generator::class)(Scramble::getGeneratorConfig('default'));

    return $document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'];
}

test('the generated 200 is an object keyed by provider, each an array of field spec objects', function (string $path): void {
    $data = generatedFieldSpecsBody($path)['properties']['data'] ?? null;

    expect($data['type'] ?? null)->toBe('object');
    expect($data['required'] ?? [])->toEqualCanonicalizing(['heygen', 'tavus']);

    foreach (['heygen', 'tavus'] as $provider) {
        $list = $data['properties'][$provider] ?? null;

        expect($list['type'] ?? null)->toBe('array', $provider);
        expect($list['items']['type'] ?? null)->toBe('object', $provider);
        expect($list['items']['required'] ?? [])->toEqualCanonicalizing(['key', 'type', 'label_key']);
        expect($list['items']['properties'])->toHaveKeys([
            'key', 'type', 'label_key', 'hint_key', 'required', 'options', 'min', 'max', 'step',
            'catalogue_resource', 'options_depend_on', 'options_by_value', 'superadmin_only',
            'superseded_by_key', 'superseded_by_values',
        ]);
    }
})->with('field spec routes');

test('the documented field spec keys are exactly the keys a real superadmin response carries', function (string $path): void {
    $items = generatedFieldSpecsBody($path)['properties']['data']['properties']['heygen']['items'];
    $documented = array_keys($items['properties']);

    // The platform route needs a bare superadmin, the organization route the superadmin acting inside an organization.
    $token = TemplateActors::token($path === '/admin/avatar-templates/field-specs' ? 'bare' : 'acting', Organization::factory()->create());
    $data = $this->withToken($token)->getJson('/api'.$path)->assertOk()->json('data');

    expect(array_keys($data))->toEqualCanonicalizing(['heygen', 'tavus']);

    $seen = [];
    foreach ($data as $provider => $specs) {
        expect($specs)->not->toBeEmpty($provider);

        foreach ($specs as $spec) {
            // every key that really comes back is documented, and every key documented as required really comes back
            expect(array_diff(array_keys($spec), $documented))->toBe([], "{$provider}.{$spec['key']} carries an undocumented key")
                ->and(array_diff($items['required'], array_keys($spec)))->toBe([], "{$provider}.{$spec['key']} lacks a required key");
            $seen = array_merge($seen, array_keys($spec));
        }
    }

    // and no documented key is dead: each one appears on at least one real spec (superadmin sees them all)
    expect(array_diff($documented, array_unique($seen)))->toBe([]);
})->with('field spec routes');
