<?php

declare(strict_types=1);

/**
 * RED — 6.2: SecretRedactor (C10, design.md D6).
 *
 * "Truncation at config('webhooks.errors.max_last_error_chars') applied AFTER
 * redaction, never before" — the ordering test below proves the bug this guards
 * against is real: truncating BEFORE redaction can leave a genuine fragment of the
 * secret in the persisted last_error / log line (the exact worst case exercised by
 * ProviderSecretTest.php:137-177 for a different secret class).
 */

use App\Services\Webhooks\SecretRedactor;

test('redact() replaces every occurrence of the secret with [redacted]', function (): void {
    $redactor = new SecretRedactor;
    $secret = 'sk_live_abc123';
    $text = 'Request failed. Response body: {"error":"invalid token '.$secret.'"}';

    $redacted = $redactor->redact($text, $secret);

    expect($redacted)->not->toContain($secret)
        ->and($redacted)->toContain('[redacted]');
});

test('redact() replaces multiple occurrences of the secret', function (): void {
    $redactor = new SecretRedactor;
    $secret = 'dup-secret-value';
    $text = "{$secret} appears twice: {$secret}";

    $redacted = $redactor->redact($text, $secret);

    expect($redacted)->not->toContain($secret)
        ->and(substr_count($redacted, '[redacted]'))->toBe(2);
});

test('redact() is a no-op when the secret is an empty string', function (): void {
    $redactor = new SecretRedactor;
    $text = 'some error text with no secret';

    expect($redactor->redact($text, ''))->toBe($text);
});

test('truncation is applied AFTER redaction — proves a naive truncate-then-redact would leak a raw secret fragment', function (): void {
    $redactor = new SecretRedactor;
    $secret = 'SUPERSECRETVALUE1234567890';
    $text = $secret.' rejected by receiver';
    $maxChars = 5;

    // Sanity check the bug is real: truncating FIRST leaves a genuine 5-char
    // fragment of the actual secret in the string, because redact() never gets a
    // chance to find the (now-partial, no-longer-matching) secret substring.
    $naiveTruncateFirst = mb_substr($text, 0, $maxChars);
    expect($naiveTruncateFirst)->toBe(mb_substr($secret, 0, $maxChars));

    $result = $redactor->redactAndTruncate($text, $secret, $maxChars);

    // No prefix of the real secret (4+ chars) survives at any length.
    for ($len = 4; $len <= strlen($secret); $len++) {
        expect($result)->not->toContain(substr($secret, 0, $len));
    }
});

test('redactAndTruncate() truncates to at most maxChars after redaction', function (): void {
    $redactor = new SecretRedactor;
    $secret = 'sk_live_abc123';
    $text = "prefix {$secret} suffix-that-is-long-enough-to-be-cut-off-eventually";

    $result = $redactor->redactAndTruncate($text, $secret, 20);

    expect(mb_strlen($result))->toBeLessThanOrEqual(20)
        ->and($result)->not->toContain($secret);
});

test('redactAndTruncate() with a maxChars larger than the redacted text returns the full redacted text', function (): void {
    $redactor = new SecretRedactor;
    $secret = 'sk_live_abc123';
    $text = "err: {$secret}";

    $result = $redactor->redactAndTruncate($text, $secret, 1000);

    expect($result)->toBe('err: [redacted]');
});
