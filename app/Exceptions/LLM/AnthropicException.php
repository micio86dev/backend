<?php

declare(strict_types=1);

namespace App\Exceptions\LLM;

use RuntimeException;

/**
 * Exception thrown by AnthropicLLMProvider on non-2xx responses or malformed replies.
 *
 * Callers (e.g. ScoreEvaluationJob) may inspect $this->isRetryable() to decide
 * whether to allow queue-level retry (5xx/transport) or treat as a terminal
 * llm_parse_error (4xx / empty content / structural failure).
 *
 * Retryable:  HTTP 5xx, transport errors (connection refused, timeout)
 * Terminal:   HTTP 4xx, empty content array, structural response failures
 */
final class AnthropicException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $retryable = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Whether the failure is transient and safe to retry via queue-level retry.
     *
     * true  → 5xx / transport error (provider overload, network failure)
     * false → 4xx / empty content / structural parse failure (terminal — retrying is futile)
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
