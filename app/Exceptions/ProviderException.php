<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a provider HTTP call fails (HeyGen or Tavus).
 *
 * Security: the raw provider response body (which may contain API key material
 * or internal endpoint details) MUST be REDACTED before this exception is created.
 * The message MUST NEVER contain raw API keys or unredacted provider responses.
 *
 * @see HeygenProvider
 * @see TavusProvider
 *
 * REQ: ProviderException — secure error handling without key leakage (C7a task 14.3)
 */
class ProviderException extends RuntimeException
{
    /**
     * Whether this failure is retryable (e.g. 429 Too Many Requests).
     * Provider 5xx/timeout = not retryable (→ participant errore + 502).
     * Provider 429 = retryable (→ session stays pending + 429 provider_busy).
     */
    private bool $retryable;

    public function __construct(string $message, bool $retryable = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->retryable = $retryable;
    }

    /**
     * Whether the caller should retry the provider call (e.g. HTTP 429).
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
