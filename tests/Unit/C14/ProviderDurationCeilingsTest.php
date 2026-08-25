<?php

declare(strict_types=1);

/**
 * Provider duration ceilings are PLAN limits, not provider limits
 * (fix/provider-duration-plan-ceilings).
 *
 * Both vendors scale maximum session length with the subscription tier, so
 * neither constant is a property of the API. These tests pin the intent so the
 * numbers cannot drift away from the plan they describe without a failure.
 */

use App\Support\AvatarTemplates\ProviderFieldSpecs;

test('HeyGen ceiling matches the Essential plan allowance (20 minutes)', function (): void {
    // Starter is 300s and Business is 3600s. If the BEAI plan changes, this
    // constant and this test change together — leaving it at a higher tier's
    // value lets an operator configure a session HeyGen cuts short mid-answer.
    expect(ProviderFieldSpecs::HEYGEN_MAX_SECONDS)->toBe(20 * 60);
});

test('the HeyGen field spec caps maxSessionDurationSec at the plan ceiling', function (): void {
    $spec = collect(ProviderFieldSpecs::for('heygen'))
        ->firstWhere('key', 'maxSessionDurationSec');

    expect($spec)->not->toBeNull()
        ->and($spec->max)->toBe(ProviderFieldSpecs::HEYGEN_MAX_SECONDS);
});

test('the Tavus field spec caps maxCallDurationSec at the plan ceiling', function (): void {
    // Tavus does NOT reject an over-plan value — it caps silently and ends the
    // conversation early. The form cap is the only thing standing between an
    // operator and an interview that truncates with no error anywhere.
    $spec = collect(ProviderFieldSpecs::for('tavus'))
        ->firstWhere('key', 'maxCallDurationSec');

    expect($spec)->not->toBeNull()
        ->and($spec->max)->toBe(ProviderFieldSpecs::TAVUS_MAX_SECONDS);
});
