<?php

declare(strict_types=1);

/**
 * ProviderErrorMessage::extract() unit tests (PR1 — diagnosability).
 *
 * Pure function: extracts a provider's own diagnostic message (message ?? error ??
 * data.message), keeps scalars only, truncates to 300 chars, and redacts the API key
 * with an assert-and-drop fail-closed guard.
 *
 * REQ: Redaction Preserves the Provider's Diagnostic Message (delta spec, interview-session)
 */

use App\Support\Provider\ProviderErrorMessage;

test('extracts the top-level message field', function (): void {
    $result = ProviderErrorMessage::extract(['message' => 'prompt is required'], 'SECRET');

    expect($result)->toBe('prompt is required');
});

test('falls back to the error field when message is absent', function (): void {
    $result = ProviderErrorMessage::extract(['error' => 'invalid request'], 'SECRET');

    expect($result)->toBe('invalid request');
});

test('falls back to data.message when message and error are both absent', function (): void {
    $result = ProviderErrorMessage::extract(['data' => ['message' => 'nested rejection']], 'SECRET');

    expect($result)->toBe('nested rejection');
});

test('message takes priority over error and data.message when all three are present', function (): void {
    $result = ProviderErrorMessage::extract([
        'message' => 'top-level wins',
        'error' => 'should be ignored',
        'data' => ['message' => 'also ignored'],
    ], 'SECRET');

    expect($result)->toBe('top-level wins');
});

test('a non-scalar message value returns null (nested structures are where a key would hide)', function (): void {
    $result = ProviderErrorMessage::extract(['message' => ['nested' => 'array']], 'SECRET');

    expect($result)->toBeNull();
});

test('no recognizable message field returns null', function (): void {
    $result = ProviderErrorMessage::extract(['status' => 'rejected'], 'SECRET');

    expect($result)->toBeNull();
});

test('a non-array, non-JSON body returns null', function (): void {
    $result = ProviderErrorMessage::extract('not json at all', 'SECRET');

    expect($result)->toBeNull();
});

test('a JSON-encoded string body is parsed the same as an array body', function (): void {
    $result = ProviderErrorMessage::extract(json_encode(['message' => 'from a JSON string']), 'SECRET');

    expect($result)->toBe('from a JSON string');
});

test('a null body returns null', function (): void {
    $result = ProviderErrorMessage::extract(null, 'SECRET');

    expect($result)->toBeNull();
});

test('truncates the extracted message to 300 characters', function (): void {
    $long = str_repeat('x', 500);

    $result = ProviderErrorMessage::extract(['message' => $long], 'SECRET');

    expect($result)->toHaveLength(300);
});

test('redacts the API key from the extracted message', function (): void {
    $result = ProviderErrorMessage::extract(
        ['message' => 'Unauthorized: SUPER_SECRET_KEY_123'],
        'SUPER_SECRET_KEY_123',
    );

    expect($result)->toBe('Unauthorized: [REDACTED]');
    expect($result)->not->toContain('SUPER_SECRET_KEY_123');
});

test('an empty API key is never redacted against (skips the replace and the check)', function (): void {
    $result = ProviderErrorMessage::extract(['message' => 'prompt is required'], '');

    expect($result)->toBe('prompt is required');
});

test('a message with both the real complaint and the key preserves the complaint and drops only the key', function (): void {
    $result = ProviderErrorMessage::extract(
        ['message' => 'prompt is required (key: LIVE_KEY_999)'],
        'LIVE_KEY_999',
    );

    expect($result)->toContain('prompt is required');
    expect($result)->not->toContain('LIVE_KEY_999');
});

test('a key straddling the truncation boundary never leaks a partial prefix', function (): void {
    // Redaction MUST run before truncation. Truncating first splits the key, so
    // neither str_replace nor the assert-and-drop check (both of which look for
    // the WHOLE key) can see it — and the surviving prefix escapes in clear text.
    $apiKey = str_repeat('K', 40);
    $message = str_repeat('x', 290).$apiKey.' tail';

    $result = ProviderErrorMessage::extract(['message' => $message], $apiKey);

    // Any non-empty run of the key's own characters surviving in the output is a leak.
    expect($result)->not->toContain(mb_substr($apiKey, 0, 4));
});

test('fails closed when redaction itself would re-create the key (a key that is a substring of the placeholder)', function (): void {
    // "RED" appears inside "[REDACTED]", so replacing it cannot remove it: the message must be
    // dropped entirely rather than logged with the key apparently still present.
    expect(ProviderErrorMessage::extract(['message' => 'bad credentials RED'], 'RED'))->toBeNull();
});

test('control: a long key that cannot re-create itself is redacted, not dropped', function (): void {
    // The counterpart of the case above: with a key that is NOT a substring of the placeholder,
    // the message survives with the key replaced, so null there is down to the fail-closed rule.
    $apiKey = 'sk-live-'.str_repeat('Z9', 24);

    expect(ProviderErrorMessage::extract(['message' => "bad credentials {$apiKey}"], $apiKey))
        ->toBe('bad credentials [REDACTED]');
});
