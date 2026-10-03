<?php

declare(strict_types=1);

/**
 * Both voice-preview routes return RAW AUDIO on 200 and `{message: <voice_preview_* code>}` on failure.
 * The committed `openapi.json` feeds the generated clients, so it must say so: a JSON-string 200 types
 * the clip as text, and undocumented 404/502/503 leave callers guessing the failure vocabulary.
 */
dataset('voice preview routes', [
    'catalogue sample' => ['/avatar-templates/catalogue-sample', 'get'],
    'synthesised sample' => ['/avatar-templates/voice-preview', 'post'],
]);

function voicePreviewOperation(string $path, string $method): array
{
    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    return $spec['paths'][$path][$method];
}

test('the 200 is binary audio, not a JSON string', function (string $path, string $method): void {
    $content = voicePreviewOperation($path, $method)['responses']['200']['content'] ?? [];

    expect(array_keys($content))->toEqualCanonicalizing(['audio/wav', 'audio/ogg', 'audio/mpeg']);

    foreach ($content as $mediaType => $media) {
        expect($media['schema'])->toMatchArray(['type' => 'string', 'format' => 'binary'], $mediaType);
    }
})->with('voice preview routes');

test('every fixed failure code is documented with its status', function (string $path, string $method): void {
    $responses = voicePreviewOperation($path, $method)['responses'];

    $expected = [
        '404' => 'voice_preview_voice_not_found',
        '502' => 'voice_preview_provider_error',
        '503' => 'voice_preview_provider_not_configured',
    ];

    foreach ($expected as $status => $code) {
        $schema = $responses[$status]['content']['application/json']['schema'] ?? null;
        expect($schema)->not->toBeNull("{$status} is undocumented");
        expect($schema['properties']['message']['enum'] ?? [$schema['properties']['message']['const'] ?? null])
            ->toBe([$code], $status);
    }

    expect($responses)->toHaveKeys(['401', '403', '429']);
})->with('voice preview routes');

test('the 422 is exactly the unavailable body or the standard validation body', function (string $path, string $method): void {
    $branches = voicePreviewOperation($path, $method)['responses']['422']['content']['application/json']['schema']['anyOf'] ?? [];

    expect($branches)->toHaveCount(2);

    [$unavailable, $validation] = $branches;

    expect($unavailable['properties']['message'])->toMatchArray(['type' => 'string', 'const' => 'voice_preview_unavailable']);
    expect($unavailable['required'])->toBe(['message']);

    // Only the synthesised sample can name a reason: the catalogue clip has a single way to be missing.
    if ($path === '/avatar-templates/voice-preview') {
        expect($unavailable['properties']['reason']['enum'])->toEqualCanonicalizing([
            'tavus_stock_voice', 'pal_uses_tavus_voice', 'pal_azure_engine', 'pal_no_voice_configured',
        ]);
    } else {
        expect($unavailable['properties'])->not->toHaveKey('reason');
    }

    expect($validation['properties'])->toHaveKeys(['message', 'errors']);
    expect($validation['required'])->toEqualCanonicalizing(['message', 'errors']);
})->with('voice preview routes');

test('the 429 declares the Retry-After header its description promises', function (string $path, string $method): void {
    $header = voicePreviewOperation($path, $method)['responses']['429']['headers']['Retry-After'] ?? null;

    expect($header)->not->toBeNull('429 declares no Retry-After');
    expect($header['schema']['type'])->toBe('integer');
    expect($header['required'])->toBeTrue();
})->with('voice preview routes');
