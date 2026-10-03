<?php

declare(strict_types=1);

/**
 * `GET /api/avatar-templates/catalogue-sample` returns RAW AUDIO, never JSON. The committed
 * `openapi.json` must say so, because the backoffice generates its typed client from it: a
 * `application/json` string 200 would type the clip as text.
 */
test('the catalogue sample 200 is documented as binary audio, not JSON', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    $content = $spec['paths']['/avatar-templates/catalogue-sample']['get']['responses']['200']['content'] ?? [];

    expect(array_keys($content))->toEqualCanonicalizing(['audio/wav', 'audio/ogg', 'audio/mpeg']);

    foreach ($content as $mediaType => $media) {
        expect($media['schema'])->toMatchArray(['type' => 'string', 'format' => 'binary'], $mediaType);
    }
});
