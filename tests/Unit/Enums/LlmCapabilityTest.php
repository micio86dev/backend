<?php

declare(strict_types=1);

/**
 * LlmCapability::mode() — total over every case (pluggable-conversation-llm PR
 * P1, design D1).
 *
 * `llm_models` has no `mode` column; mode is DERIVED from `capability` by this
 * exhaustive `match`, deliberately written with NO default arm. A future
 * capability case added without a matching `mode()` arm must fail this test
 * loudly (an `\UnhandledMatchError`) rather than silently falling through to
 * a default that happens to be safe today and wrong tomorrow.
 *
 * REQ: conversation-llm "Mode is derived from the bound model's capability,
 *      and native_duplex is refused at every write path"
 */

use App\Enums\LlmCapability;
use App\Enums\LlmMode;

test('mode() is total over every LlmCapability case', function (LlmCapability $capability): void {
    // The assertion IS the exhaustiveness check: a case with no matching arm
    // in mode()'s match expression throws \UnhandledMatchError, which fails
    // this test for that case — not a default value that hides the gap.
    expect($capability->mode())->toBeInstanceOf(LlmMode::class);
})->with(LlmCapability::cases());

test('text capability derives the managed mode', function (): void {
    expect(LlmCapability::Text->mode())->toBe(LlmMode::Managed);
});

test('native_duplex capability derives the native-duplex mode', function (): void {
    expect(LlmCapability::NativeDuplex->mode())->toBe(LlmMode::NativeDuplex);
});
