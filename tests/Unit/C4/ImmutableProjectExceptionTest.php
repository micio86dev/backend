<?php

declare(strict_types=1);

/**
 * RED — 3.3: ImmutableProjectException renders HTTP 422 (C4).
 *
 * Asserts render() returns a Response with HTTP 422 status.
 * Refs design: ImmutableProjectException pattern; status lifecycle and immutable-field enforcement.
 */

use App\Exceptions\ImmutableProjectException;
use Illuminate\Http\Request;

test('ImmutableProjectException render() returns HTTP 422', function (): void {
    $exception = new ImmutableProjectException('Cannot change immutable fields on an active project.');
    $request = Request::create('/api/test', 'PATCH');

    $response = $exception->render($request);

    expect($response->getStatusCode())->toBe(422);
});

test('ImmutableProjectException render() response body is JSON with message', function (): void {
    $exception = new ImmutableProjectException('Cannot change immutable fields on an active project.');
    $request = Request::create('/api/test', 'PATCH');

    $response = $exception->render($request);

    $body = json_decode($response->getContent(), true);
    expect($body)->toHaveKey('message');
    expect($body['message'])->toBe('Cannot change immutable fields on an active project.');
});
