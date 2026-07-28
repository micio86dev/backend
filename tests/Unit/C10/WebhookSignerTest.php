<?php

declare(strict_types=1);

/**
 * RED — 6.1: WebhookSigner (C10, design.md D6).
 *
 * "Signature verifies against a fixed test vector" (spec scenario): the fixed hex
 * below was computed OUTSIDE this codebase (`php -r 'echo hash_hmac(...)'`, pasted as
 * a literal) — the test does NOT call hash_hmac itself, so it guards against the
 * production code silently changing the signed-string format, the algorithm, or the
 * argument order, not just against WebhookSigner disagreeing with itself.
 *
 * The divergence test at the bottom is the coordinator-requested guard: it proves
 * that signing raw bytes produced by ANY encoder other than WebhookSigner::encode()
 * yields a DIFFERENT signature — i.e. body and signature diverging is not a
 * theoretical risk, it is the default outcome unless encode() is the single source
 * of the transmitted bytes (design.md D6 — `Http::post($url, $array)` is forbidden
 * precisely because Laravel would re-encode and silently break every receiver).
 */

use App\Services\Webhooks\WebhookSigner;

test('signs a fixed (timestamp, body, secret) vector to a constant expected hex', function (): void {
    $signer = new WebhookSigner;

    $timestamp = 1700000000;
    $rawBody = '{"delivery_id":"11111111-1111-1111-1111-111111111111","event":"evaluation"}';
    $secret = 'whsec_test_fixed_vector_secret';

    // Computed independently outside this codebase — see class doc above.
    $expectedHex = '8a20c120e2d10b49688518c9f122dd84ec17d9e20aa17f16fd76e52bcefcfcf5';

    expect($signer->sign($timestamp, $rawBody, $secret))->toBe($expectedHex);
});

test('header() formats as v1={hex}', function (): void {
    $signer = new WebhookSigner;
    $hex = $signer->sign(1700000000, '{}', 'secret');

    expect($signer->header($hex))->toBe('v1='.$hex)
        ->and($signer->header($hex))->toStartWith('v1=');
});

test('verify() returns true for a matching signature', function (): void {
    $signer = new WebhookSigner;
    $hex = $signer->sign(1700000000, '{"a":1}', 'secret');

    expect($signer->verify(1700000000, '{"a":1}', 'secret', $hex))->toBeTrue();
});

test('verify() returns false when the body is tampered', function (): void {
    $signer = new WebhookSigner;
    $hex = $signer->sign(1700000000, '{"a":1}', 'secret');

    expect($signer->verify(1700000000, '{"a":2}', 'secret', $hex))->toBeFalse();
});

test('verify() returns false when the timestamp is tampered', function (): void {
    $signer = new WebhookSigner;
    $hex = $signer->sign(1700000000, '{"a":1}', 'secret');

    expect($signer->verify(1700000001, '{"a":1}', 'secret', $hex))->toBeFalse();
});

test('verify() returns false when the secret is wrong', function (): void {
    $signer = new WebhookSigner;
    $hex = $signer->sign(1700000000, '{"a":1}', 'secret');

    expect($signer->verify(1700000000, '{"a":1}', 'wrong-secret', $hex))->toBeFalse();
});

test('encode() produces JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE bytes', function (): void {
    $signer = new WebhookSigner;
    $payload = ['url' => 'https://a.com/b', 'name' => 'héllo'];

    // Computed independently — see class doc above.
    expect($signer->encode($payload))->toBe('{"url":"https://a.com/b","name":"héllo"}');
});

test('encode() is deterministic for the same payload', function (): void {
    $signer = new WebhookSigner;
    $payload = ['x' => 1, 'y' => ['z' => 2]];

    expect($signer->encode($payload))->toBe($signer->encode($payload));
});

test('signing bytes from a DIFFERENT encoder than encode() yields a DIFFERENT signature — proves body/signature divergence is real, not hypothetical', function (): void {
    $signer = new WebhookSigner;
    $payload = ['url' => 'https://a.com/b', 'name' => 'héllo'];

    $canonical = $signer->encode($payload);
    $default = json_encode($payload, JSON_THROW_ON_ERROR); // the forbidden alternative (design.md D6)

    expect($canonical)->not->toBe($default);

    $hexFromCanonical = $signer->sign(1700000000, $canonical, 'secret');
    $hexFromDefault = $signer->sign(1700000000, $default, 'secret');

    expect($hexFromCanonical)->not->toBe($hexFromDefault);

    // The receiver-side check: verifying the DEFAULT-encoded body against the
    // CANONICAL signature (i.e. what would happen if the HTTP call re-encoded the
    // array instead of transmitting encode()'s raw bytes) MUST fail.
    expect($signer->verify(1700000000, $default, 'secret', $hexFromCanonical))->toBeFalse();
});
