<?php

declare(strict_types=1);

/**
 * Expand::parse() unit tests (public-api step 3) — SPEC.md §3.2 "Expansion".
 */

use App\Exceptions\PublicApi\InvalidExpandException;
use App\Support\PublicApi\Expand;
use Illuminate\Http\Request;

test('no ?expand= returns an empty list', function (): void {
    $request = Request::create('/v1/interviews');

    expect(Expand::parse($request, ['project']))->toBe([]);
});

test('a single allowed name is returned', function (): void {
    $request = Request::create('/v1/interviews', 'GET', ['expand' => 'project']);

    expect(Expand::parse($request, ['project']))->toBe(['project']);
});

test('duplicates are de-duplicated, order of first appearance preserved', function (): void {
    $request = Request::create('/v1/interviews', 'GET', ['expand' => 'project,project']);

    expect(Expand::parse($request, ['project']))->toBe(['project']);
});

test('more than 3 names throws InvalidExpandException', function (): void {
    $request = Request::create('/v1/interviews', 'GET', ['expand' => 'a,b,c,d']);

    expect(fn () => Expand::parse($request, ['a', 'b', 'c', 'd']))
        ->toThrow(InvalidExpandException::class);
});

test('exactly 3 names is allowed', function (): void {
    $request = Request::create('/v1/interviews', 'GET', ['expand' => 'a,b,c']);

    expect(Expand::parse($request, ['a', 'b', 'c']))->toBe(['a', 'b', 'c']);
});

test('an unrecognised name throws InvalidExpandException', function (): void {
    $request = Request::create('/v1/interviews', 'GET', ['expand' => 'nope']);

    expect(fn () => Expand::parse($request, ['project']))
        ->toThrow(InvalidExpandException::class);
});

test('a non-string ?expand= (array) throws InvalidExpandException', function (): void {
    $request = Request::create('/v1/interviews', 'GET', ['expand' => ['a', 'b']]);

    expect(fn () => Expand::parse($request, ['project']))
        ->toThrow(InvalidExpandException::class);
});
