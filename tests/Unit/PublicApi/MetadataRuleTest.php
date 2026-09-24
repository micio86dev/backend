<?php

declare(strict_types=1);

/**
 * App\Rules\PublicApi\Metadata unit tests (public-api step 3) — SPEC.md
 * §3.2 "Metadata".
 */

use App\Rules\PublicApi\Metadata;
use Illuminate\Support\Facades\Validator;

function validateMetadata(mixed $value): Illuminate\Contracts\Validation\Validator
{
    return Validator::make(['metadata' => $value], ['metadata' => ['sometimes', new Metadata]]);
}

test('an object within every limit passes', function (): void {
    $validator = validateMetadata(['ats_application_id' => 'A-4471']);

    expect($validator->fails())->toBeFalse();
});

test('exactly 20 keys passes', function (): void {
    $value = [];
    foreach (range(1, 20) as $i) {
        $value["k{$i}"] = 'v';
    }

    expect(validateMetadata($value)->fails())->toBeFalse();
});

test('21 keys fails', function (): void {
    $value = [];
    foreach (range(1, 21) as $i) {
        $value["k{$i}"] = 'v';
    }

    expect(validateMetadata($value)->fails())->toBeTrue();
});

test('a 40-char key passes, a 41-char key fails', function (): void {
    expect(validateMetadata([str_repeat('k', 40) => 'v'])->fails())->toBeFalse();
    expect(validateMetadata([str_repeat('k', 41) => 'v'])->fails())->toBeTrue();
});

test('a 500-char value passes, a 501-char value fails', function (): void {
    expect(validateMetadata(['k' => str_repeat('v', 500)])->fails())->toBeFalse();
    expect(validateMetadata(['k' => str_repeat('v', 501)])->fails())->toBeTrue();
});

test('a non-string value fails', function (): void {
    expect(validateMetadata(['k' => 123])->fails())->toBeTrue();
    expect(validateMetadata(['k' => ['nested' => 'array']])->fails())->toBeTrue();
});

test('a non-object value fails', function (): void {
    expect(validateMetadata('not-an-object')->fails())->toBeTrue();
});
