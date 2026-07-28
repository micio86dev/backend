<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

/**
 * SecretRedactor — strips a project's webhook_secret out of anything BEAI persists or
 * logs about a delivery attempt (C10 — Webhooks Integration, design.md D6).
 *
 * Ordering is security-critical: `redactAndTruncate()` redacts BEFORE truncating.
 * Truncating first can leave a genuine prefix fragment of the real secret in the
 * final string if the truncation boundary falls inside the secret — see
 * SecretRedactorTest.php for a concrete demonstration of the leak this prevents.
 * Mirrors the ProviderSecretTest.php:137-177 non-leak pattern for a different secret
 * class (provider API keys); here the receiver's own error response is the untrusted
 * input a webhook_secret could be reflected back through.
 *
 * REQ: Secret redaction before persistence/logging (C10 D6)
 */
final class SecretRedactor
{
    /**
     * Replace every occurrence of $secret in $text with "[redacted]". A no-op when
     * $secret is empty — never redacts unrelated text on a missing/empty secret.
     */
    public function redact(string $text, string $secret): string
    {
        if ($secret === '') {
            return $text;
        }

        return str_replace($secret, '[redacted]', $text);
    }

    /**
     * Redact, THEN truncate to at most $maxChars. Never the reverse order — see class
     * doc.
     */
    public function redactAndTruncate(string $text, string $secret, int $maxChars): string
    {
        return mb_substr($this->redact($text, $secret), 0, $maxChars);
    }
}
