<?php

declare(strict_types=1);

namespace App\Actions\Participant;

/**
 * Who authorized an evaluation retry (scoring-retry-rt-b, design D4).
 *
 * The action serves two surfaces: an operator in the backoffice (`user`) and an
 * external calling system over M2M (`api_client`). The actor is recorded in the
 * interim log line and in the audit row. `AuditRecorder` reads `Auth::user()`,
 * which is null on the M2M surface, so the client id travels in the audit
 * payload and not only in `actor_id`.
 *
 * Built through the two named constructors only, so `type` and the matching id
 * can never disagree.
 */
final readonly class RetryActor
{
    public const TYPE_USER = 'user';

    public const TYPE_API_CLIENT = 'api_client';

    private function __construct(
        public string $type,
        public ?int $userId,
        public ?int $apiClientId,
    ) {}

    public static function user(?int $userId): self
    {
        return new self(self::TYPE_USER, $userId, null);
    }

    public static function apiClient(int $apiClientId): self
    {
        return new self(self::TYPE_API_CLIENT, null, $apiClientId);
    }
}
