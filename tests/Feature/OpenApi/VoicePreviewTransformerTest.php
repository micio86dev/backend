<?php

declare(strict_types=1);

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;

/**
 * The document transformer in `AppServiceProvider` is what turns Scramble's inferred JSON-string 200 of the two
 * audio routes into binary audio, and what declares `Retry-After` on their 429. The committed `openapi.json` only
 * proves the LAST export ran it, so this drives a real in-process generation (nothing is written to disk).
 * Only these two routes are asserted: the rest of the export depends on the database driver.
 */
dataset('voice preview generated operations', [
    'catalogue sample' => ['/avatar-templates/catalogue-sample', 'get'],
    'synthesised sample' => ['/avatar-templates/voice-preview', 'post'],
]);

function generatedVoicePreviewOperation(string $path, string $method): array
{
    static $document = null;
    $document ??= app(Generator::class)(Scramble::getGeneratorConfig('default'));

    return $document['paths'][$path][$method];
}

test('the generated 200 carries only audio media types, all binary', function (string $path, string $method): void {
    $content = generatedVoicePreviewOperation($path, $method)['responses']['200']['content'] ?? [];

    expect(array_keys($content))->toEqualCanonicalizing(['audio/wav', 'audio/ogg', 'audio/mpeg']);

    foreach ($content as $mediaType => $media) {
        expect($media['schema'])->toMatchArray(['type' => 'string', 'format' => 'binary'], $mediaType);
    }
})->with('voice preview generated operations');

test('the generated 429 declares an integer Retry-After header', function (string $path, string $method): void {
    $header = generatedVoicePreviewOperation($path, $method)['responses']['429']['headers']['Retry-After'] ?? null;

    expect($header)->not->toBeNull('429 declares no Retry-After');
    expect($header['schema']['type'] ?? null)->toBe('integer');
    expect($header['required'] ?? false)->toBeTrue();
})->with('voice preview generated operations');
