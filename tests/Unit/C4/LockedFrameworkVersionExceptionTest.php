<?php

declare(strict_types=1);

/**
 * RED — 2.1: LockedFrameworkVersionException renders HTTP 422 (C4).
 *
 * Asserts render() returns a Response with HTTP 422 status.
 * Refs design: replace RuntimeException with LockedFrameworkVersionException.
 */

use App\Exceptions\LockedFrameworkVersionException;
use Illuminate\Http\Request;

test('LockedFrameworkVersionException render() returns HTTP 422', function (): void {
    $exception = new LockedFrameworkVersionException('FrameworkVersion [1] is locked and cannot be mutated.');
    $request = Request::create('/api/test', 'PATCH');

    $response = $exception->render($request);

    expect($response->getStatusCode())->toBe(422);
});

test('LockedFrameworkVersionException render() response body is JSON with message', function (): void {
    $exception = new LockedFrameworkVersionException('FrameworkVersion [1] is locked and cannot be mutated.');
    $request = Request::create('/api/test', 'PATCH');

    $response = $exception->render($request);

    $body = json_decode($response->getContent(), true);
    expect($body)->toHaveKey('message');
});
