<?php

declare(strict_types=1);

namespace App\Exceptions\Audit;

use RuntimeException;

/**
 * Thrown by `TypesafeJevJudge` on transport failure, non-2xx responses, or an
 * unparseable envelope (design D2/D3). Mirrors `AnthropicException`.
 *
 * NEVER thrown for a per-subject problem — a missing or malformed verdict
 * within an otherwise-successful call is reported by that subject's absence
 * from `AuditBatchResult::$verdicts`, never by throwing.
 *
 * Retryable:  HTTP 5xx, transport errors (connection refused, timeout)
 * Terminal:   HTTP 4xx, unparseable envelope
 */
final class AuditJudgeException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $retryable = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Whether the failure is transient and safe to retry.
     *
     * true  → 5xx / transport error (vendor overload, network failure)
     * false → 4xx / unparseable envelope (retrying is futile)
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
