<?php

declare(strict_types=1);

/**
 * App\Support\PublicApi\Problem unit tests — covers the `type` URL base
 * resolution branches (configured `public_api.developers_url` vs. the
 * built-in fallback) that the many HTTP-level Problem+json tests elsewhere
 * never vary, since none of them override that config key.
 */

use App\Support\PublicApi\Problem;
use Illuminate\Http\Request;

test('type uses the fallback developers URL when public_api.developers_url is unset', function (): void {
    config(['public_api.developers_url' => null]);

    $response = Problem::make(Request::create('/v1/_probe'), 400, 'validation_failed', 'Bad');

    expect($response->getData(true)['type'])->toBe('https://developers.beai.example/errors/validation_failed');
});

test('type uses the configured developers URL when set', function (): void {
    config(['public_api.developers_url' => 'https://docs.example.test']);

    $response = Problem::make(Request::create('/v1/_probe'), 400, 'validation_failed', 'Bad');

    expect($response->getData(true)['type'])->toBe('https://docs.example.test/errors/validation_failed');
});
