<?php

declare(strict_types=1);

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Foundation\Testing\DatabaseTransactions;

// Generating the document introspects request classes whose rules() can open a draft catalogue revision;
// roll it back so a serial run does not leak it (see VoicePreviewTransformerTest).
uses(DatabaseTransactions::class);

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
