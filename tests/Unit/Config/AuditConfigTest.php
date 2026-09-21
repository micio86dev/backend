<?php

declare(strict_types=1);

/**
 * RED — P1.1: shipped `scoring.audit` config defaults (proposal AD-1, design D13).
 *
 * `TruncationRetryConfigTest` idiom: the SHIPPED defaults are pinned here so
 * changing them requires editing this test — deliberate and visible — while an
 * operator retains the env override. `enabled` defaulting `true` means only
 * "an operator MAY ask", never "the platform spends" — AD-1, the
 * scoring-audit spec's "config('scoring.audit') Ships With enabled Defaulting
 * true" requirement.
 */
test('shipped scoring.audit defaults: enabled=true, api_key empty, judge_model, prompt_version, timeout_seconds', function (): void {
    expect(config('scoring.audit.enabled'))->toBeTrue()
        ->and(config('scoring.audit.api_key'))->toBe('')
        ->and(config('scoring.audit.base_url'))->toBe('https://api.typesafe.ai')
        ->and(config('scoring.audit.judge_model'))->toBe('jev-latest')
        ->and(config('scoring.audit.prompt_version'))->toBe('1.0.0')
        ->and(config('scoring.audit.timeout_seconds'))->toBe(30)
        ->and(config('scoring.audit.cost_rates_usd_per_million'))->toBe([]);
});

test('scoring.audit ships no support_threshold key and no batch_size key', function (): void {
    $audit = config('scoring.audit');

    expect($audit)->toBeArray()
        ->and(array_key_exists('support_threshold', $audit))->toBeFalse()
        ->and(array_key_exists('batch_size', $audit))->toBeFalse();
});
