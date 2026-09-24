<?php

declare(strict_types=1);

/**
 * ApiMode unit tests (public-api step 2 — SPEC.md §3.7 "Test mode").
 */

use App\Support\PublicApi\ApiMode;

test('defaults to live', function (): void {
    $mode = new ApiMode;

    expect($mode->get())->toBe('live');
    expect($mode->isTest())->toBeFalse();
});

test('set("test") switches to test mode', function (): void {
    $mode = new ApiMode;
    $mode->set('test');

    expect($mode->get())->toBe('test');
    expect($mode->isTest())->toBeTrue();
});

test('set() with anything other than "test" normalises to live', function (): void {
    $mode = new ApiMode;
    $mode->set('test');
    $mode->set('bogus');

    expect($mode->get())->toBe('live');
    expect($mode->isTest())->toBeFalse();
});
