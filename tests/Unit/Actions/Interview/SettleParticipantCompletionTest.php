<?php

declare(strict_types=1);

/**
 * `App\Actions\Interview\SettleParticipantCompletion::narrowOrganizationId()`
 * — step 6 review follow-up, finding 5: `Project::value('organization_id')`
 * is declared `mixed`, and some driver/config combinations return an
 * integer column as a numeric PHP string rather than a native `int`. A bare
 * `is_int()` check rejected that value as if the project had not resolved
 * at all, silently stranding the participant in `in_corso` instead of
 * settling them.
 */

use App\Actions\Interview\SettleParticipantCompletion;

function narrowOrganizationId(mixed $orgId): ?int
{
    $method = new ReflectionMethod(SettleParticipantCompletion::class, 'narrowOrganizationId');

    return $method->invoke(new SettleParticipantCompletion, $orgId);
}

test('accepts a native int organization id', function (): void {
    expect(narrowOrganizationId(42))->toBe(42);
});

test('accepts a numeric-string organization id, casting it to int', function (): void {
    expect(narrowOrganizationId('42'))->toBe(42);
});

test('rejects a non-numeric string', function (): void {
    expect(narrowOrganizationId('abc'))->toBeNull();
});

test('rejects a numeric string that is not a plain unsigned digit run (e.g. "1e3", "+1", "1.0", " 1")', function (): void {
    expect(narrowOrganizationId('1e3'))->toBeNull();
    expect(narrowOrganizationId('+1'))->toBeNull();
    expect(narrowOrganizationId('1.0'))->toBeNull();
    expect(narrowOrganizationId(' 1'))->toBeNull();
});

test('rejects null', function (): void {
    expect(narrowOrganizationId(null))->toBeNull();
});

test('rejects zero and negative values, int or numeric-string alike', function (): void {
    expect(narrowOrganizationId(0))->toBeNull();
    expect(narrowOrganizationId(-1))->toBeNull();
    expect(narrowOrganizationId('0'))->toBeNull();
});

test('rejects a float', function (): void {
    expect(narrowOrganizationId(4.2))->toBeNull();
});
