<?php

declare(strict_types=1);

/**
 * ApiMode unit tests (public-api step 2 — SPEC.md §3.7 "Test mode").
 */

use App\Enums\ApiKeyMode;
use App\Support\PublicApi\ApiMode;

test('defaults to live', function (): void {
    $mode = new ApiMode;

    expect($mode->get())->toBe('live');
    expect($mode->isTest())->toBeFalse();
});

test('set(ApiKeyMode::Test) switches to test mode', function (): void {
    $mode = new ApiMode;
    $mode->set(ApiKeyMode::Test);

    expect($mode->get())->toBe('test');
    expect($mode->isTest())->toBeTrue();
});

test('set(ApiKeyMode::Live) switches back to live', function (): void {
    $mode = new ApiMode;
    $mode->set(ApiKeyMode::Test);
    $mode->set(ApiKeyMode::Live);

    expect($mode->get())->toBe('live');
    expect($mode->isTest())->toBeFalse();
});
