<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only sanctioned way to write a TENANT-scoped audit row (C13).
 *
 * Two properties live here rather than at each call site, and both are the kind
 * that fail silently when left to callers.
 *
 * **Redaction is central.** A call site that forgets to strip a secret is the
 * normal failure mode, and the consequence is a permanent, queryable copy of a
 * credential in a table built to be read. The denylist itself lives in
 * `AuditRedactor` (framework-catalogue-authoring PR8) — shared with
 * `App\Support\Superadmin\PlatformAuditWriter`, the ONE other class permitted
 * to write `audit_logs`, so both writers redact by the same rule rather than
 * two independently-maintained copies.
 *
 * **Failure is contained.** A write that throws must never fail or roll back
 * the mutation it was recording. The mutation is the user's intent; the audit
 * row is a side effect. Losing the row is bad; losing the user's work because
 * logging it failed is worse, and it turns an observability outage into a
 * functional one.
 */
final class AuditRecorder
{
    public function __construct(
        private readonly AuditRedactor $redactor = new AuditRedactor,
    ) {}

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
                'before' => $before === null ? null : $this->redactor->redact($before),
                'after' => $after === null ? null : $this->redactor->redact($after),
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
}
