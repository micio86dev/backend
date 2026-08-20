<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ProviderFailureClass;
use RuntimeException;

/**
 * Thrown when a provider HTTP call fails (HeyGen or Tavus).
 *
 * Security: the raw provider response body (which may contain API key material
 * or internal endpoint details) MUST be REDACTED before this exception is created.
 * The message MUST NEVER contain raw API keys or unredacted provider responses.
 *
 * PR1 (delta spec D4): the binary retryable/hard-failure flag became a three-way
 * `ProviderFailureClass`. `isRetryable()` is KEPT as a derived accessor
 * (`class === Throttle`) so no existing caller — including the pending
 * `participant-error-recovery` change — breaks on this rename.
 *
 * @see HeygenProvider
 * @see TavusProvider
 *
 * REQ: ProviderException — secure error handling without key leakage (C7a task 14.3)
 * REQ: Provider 4xx Is a Client Contract Error, Not a Provider Failure (delta spec D4)
 */
class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ProviderFailureClass $class = ProviderFailureClass::Upstream,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The three-way classification this failure belongs to.
     *
     * `InterviewController::handleProviderFailure()` is the ONLY place this is
     * switched on to decide HTTP status + session/participant writes.
     */
    public function failureClass(): ProviderFailureClass
    {
        return $this->class;
    }

    /**
     * Whether the caller should retry the provider call (e.g. HTTP 429).
     *
     * Derived from `failureClass()` — kept so existing callers (and the pending
     * `participant-error-recovery` change) never see a breaking signature change.
     */
    public function isRetryable(): bool
    {
        return $this->class === ProviderFailureClass::Throttle;
    }
}
