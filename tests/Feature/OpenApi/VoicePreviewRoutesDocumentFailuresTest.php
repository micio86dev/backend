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
    expect(json_encode($responses['422']))->toContain('voice_preview_unavailable');
})->with('voice preview routes');
