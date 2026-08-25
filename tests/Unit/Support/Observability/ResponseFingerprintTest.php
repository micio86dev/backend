<?php

declare(strict_types=1);

/**
 * RED — A3.2: ResponseFingerprint (C13, design.md D6).
 *
 * The CHECK constraint at the DB layer is the real enforcement (D6), but the
 * value object itself is asserted here too: `ReflectionClass` proves no
 * property can hold response content BY TYPE (`int`, `bool`, a `string`
 * whose only legal shape is 64 lowercase hex chars) — not merely by
 * convention.
 */

use App\Services\Scoring\ResponseEnvelopeStripper;
use App\Support\Observability\ResponseFingerprint;

test('bytes is the exact byte length of the content', function (): void {
    $fingerprint = ResponseFingerprint::from('{"behaviors": []}', new ResponseEnvelopeStripper);

    expect($fingerprint->bytes)->toBe(strlen('{"behaviors": []}'));
});

test('fenced is true when the content was fenced, false otherwise', function (): void {
    $fenced = ResponseFingerprint::from("```json\n{\"behaviors\": []}\n```", new ResponseEnvelopeStripper);
    $unfenced = ResponseFingerprint::from('{"behaviors": []}', new ResponseEnvelopeStripper);

    expect($fenced->fenced)->toBeTrue()
        ->and($unfenced->fenced)->toBeFalse();
});

test('sha256 is a 64-character lowercase hex digest matching hash(sha256, content)', function (): void {
    $content = '{"behaviors": []}';
    $fingerprint = ResponseFingerprint::from($content, new ResponseEnvelopeStripper);

    expect($fingerprint->sha256)->toBe(hash('sha256', $content))
        ->and($fingerprint->sha256)->toMatch('/^[0-9a-f]{64}$/');
});

test('no property can hold response content, by type — ReflectionClass property-type assertion', function (): void {
    $reflection = new ReflectionClass(ResponseFingerprint::class);
    $types = [];

    foreach ($reflection->getProperties() as $property) {
        $type = $property->getType();
        expect($type)->not->toBeNull("Property {$property->getName()} must be typed.");
        $types[$property->getName()] = (string) $type;
    }

    expect($types)->toBe([
        'bytes' => 'int',
        'fenced' => 'bool',
        // The ONE text-capable property. Its legal shape (64 lowercase hex
        // chars, asserted above) cannot hold any response fragment.
        'sha256' => 'string',
    ]);
});
