<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only sanctioned way to write an audit row (C13).
 *
 * Two properties live here rather than at each call site, and both are the kind
 * that fail silently when left to callers.
 *
 * **Redaction is central.** A call site that forgets to strip a secret is the
 * normal failure mode, and the consequence is a permanent, queryable copy of a
 * credential in a table built to be read. The denylist runs here, recursively,
 * on every payload.
 *
 * **Failure is contained.** A write that throws must never fail or roll back
 * the mutation it was recording. The mutation is the user's intent; the audit
 * row is a side effect. Losing the row is bad; losing the user's work because
 * logging it failed is worse, and it turns an observability outage into a
 * functional one.
 */
final class AuditRecorder
{
    /**
     * Attribute names whose VALUES never reach the trail.
     *
     * Names are kept and values are not, deliberately: "the webhook secret was
     * rotated" is exactly what an auditor needs to see, and the secret itself
     * is exactly what they must not.
     *
     * @var list<string>
     */
    private const DENYLIST = [
        'password',
        'password_confirmation',
        'key_hash',
        'api_key',
        'webhook_secret',
        'secret',
        'token',
        'remember_token',
        'jwt_secret',
    ];

    private const REDACTED = '[redacted]';

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        string $subjectType,
        ?int $subjectId,
        ?array $before = null,
        ?array $after = null,
    ): void {
        try {
            /** @var User|null $actor */
            $actor = Auth::user();

            AuditLog::create([
                // Null when an M2M client or a console command acted. Recording
                // that anonymously beats not recording it: "something changed
                // and no human did it" is the event an auditor most wants.
                'actor_id' => $actor?->getKey(),
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'before' => $before === null ? null : $this->redact($before),
                'after' => $after === null ? null : $this->redact($after),
            ]);
        } catch (Throwable $e) {
            // Never propagate. See the class docblock.
            Log::error('audit.record.failed', [
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Strip credential values at any depth.
     *
     * Recursive because a changed attribute can itself be an array — a project's
     * webhook config, for instance — and a top-level-only pass would let a
     * nested secret through while looking like it had done its job.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function redact(array $payload): array
    {
        $out = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $out[$key] = self::REDACTED;

                continue;
            }

            $out[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $out;
    }

    private function isSensitive(string $key): bool
    {
        $normalised = strtolower($key);

        if (in_array($normalised, self::DENYLIST, true)) {
            return true;
        }

        // Catches api_token, access_token, refresh_token and anything else that
        // follows the convention without needing to enumerate it.
        return str_ends_with($normalised, '_token')
            || str_ends_with($normalised, '_secret');
    }
}
