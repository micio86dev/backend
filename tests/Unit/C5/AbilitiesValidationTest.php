<?php

declare(strict_types=1);

/**
 * Abilities validation unit tests (C5 — M2M API Authentication).
 *
 * Asserts:
 * - all canonical abilities are accepted by the validator
 * - unknown ability causes validation failure
 * - can() returns false for absent ability
 * - can() is strict (no partial match)
 *
 * REQ-6
 */

use App\Models\ApiClient;
use App\Services\AbilitiesValidator;

// ─── Canonical set validation ─────────────────────────────────────────────────

test('all canonical abilities are accepted', function (): void {
    $canonical = config('m2m_abilities.allowed');

    foreach ($canonical as $ability) {
        expect(AbilitiesValidator::validate([$ability]))->toBeTrue();
    }
});

test('empty abilities array is accepted (no abilities granted)', function (): void {
    expect(AbilitiesValidator::validate([]))->toBeTrue();
});

test('unknown ability fails validation', function (): void {
    expect(AbilitiesValidator::validate(['unknown:action']))->toBeFalse();
});

test('mix of canonical and unknown fails validation', function (): void {
    expect(AbilitiesValidator::validate(['participants:read', 'admin:delete_everything']))->toBeFalse();
});

// ─── ApiClient::can() ─────────────────────────────────────────────────────────

test('can() returns false when ability is absent from client abilities', function (): void {
    $client = new ApiClient;
    $client->abilities = ['participants:read'];

    expect($client->can('evaluations:read'))->toBeFalse();
});

test('can() is strict — participants:read does not match participants', function (): void {
    $client = new ApiClient;
    $client->abilities = ['participants:read'];

    // 'participants' is NOT an exact match
    expect($client->can('participants'))->toBeFalse();
});

test('can() is strict — uppercase does not match lowercase canonical', function (): void {
    $client = new ApiClient;
    $client->abilities = ['PARTICIPANTS:READ'];

    // canonical is lowercase
    expect($client->can('participants:read'))->toBeFalse();
});

// ─── Canonical ability coverage ───────────────────────────────────────────────

test('canonical set contains all 6 expected abilities', function (): void {
    $expected = [
        'participants:create',
        'participants:read',
        'evaluations:read',
        'progress:read',
        'projects:read',
        'sso_link:generate',
    ];

    $canonical = config('m2m_abilities.allowed');

    foreach ($expected as $ability) {
        expect($canonical)->toContain($ability);
    }
});
