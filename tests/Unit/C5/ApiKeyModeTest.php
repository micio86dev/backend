<?php

declare(strict_types=1);

/**
 * ApiKeyMode enum unit tests (public-api step 2 review follow-up, finding 3).
 *
 * Asserts:
 * - marker() returns the correct `beai_live_`/`beai_test_` string per case.
 * - fromMarker() recognises both markers and returns null for anything else.
 */

use App\Enums\ApiKeyMode;

test('marker() returns the beai_live_/beai_test_ string for each case', function (): void {
    expect(ApiKeyMode::Live->marker())->toBe('beai_live_');
    expect(ApiKeyMode::Test->marker())->toBe('beai_test_');
});

test('fromMarker() recognises a beai_live_ key as Live', function (): void {
    expect(ApiKeyMode::fromMarker('beai_live_'.str_repeat('a', 96)))->toBe(ApiKeyMode::Live);
});

test('fromMarker() recognises a beai_test_ key as Test', function (): void {
    expect(ApiKeyMode::fromMarker('beai_test_'.str_repeat('b', 96)))->toBe(ApiKeyMode::Test);
});

test('fromMarker() returns null for a malformed or unrecognised key', function (): void {
    expect(ApiKeyMode::fromMarker('not-a-beai-key'))->toBeNull();
    expect(ApiKeyMode::fromMarker(''))->toBeNull();
});
