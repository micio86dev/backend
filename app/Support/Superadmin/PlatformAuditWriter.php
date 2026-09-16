<?php

declare(strict_types=1);

namespace App\Support\Superadmin;

use App\Support\Audit\AuditRedactor;
use Illuminate\Support\Facades\DB;

/**
 * Writes an `audit_logs` row for a platform-scoped mutation — a catalogue
 * write or a revision publish, both acting with no tenant at all
 * (framework-catalogue-authoring PR8, design D13).
 *
 * `DB::table('audit_logs')->insert([...])` — deliberately NOT Eloquent.
 * `AuditLog extends TenantModel`, and `TenantScoped::creating` unconditionally
 * stamps the AMBIENT tenant and throws `MissingTenantContextException` when
 * none is resolved — exactly the state a superadmin editing the shared
 * catalogue is in. There is no scope to bypass here: this class writes
 * `organization_id: null` directly, which is not a value `TenantScoped`
 * would ever produce.
 *
 * THIS IS THE ONE NAMED BYPASS. `CrossTenantReaderInventoryArchTest` pins the
 * set of files under `App\Support\Superadmin\` that write `audit_logs`
 * directly to exactly this class, so a second writer cannot appear quietly.
 *
 * `before`/`after` are restricted to the CHANGED attributes, never a full row
 * dump — a create's `after` is what was submitted, an update's `before`/
 * `after` are the dirty attributes before and after the save, a delete's
 * `before` is the row that no longer exists. `revision_id` rides inside
 * `after` (or `before` for a delete) rather than as its own column, per
 * design D13 — the revision a piece of catalogue content belonged to is
 * itself part of what happened, not a separate fact about the row.
 *
 * REDACTED through `AuditRedactor` — the SAME denylist `AuditRecorder`
 * (the tenant-scoped writer) applies, per D13's "subject to this
 * capability's existing redaction ... rules". Catalogue content carries no
 * credentials today, but a writer that skips redaction on the assumption
 * that its OWN payloads happen to be safe is exactly the drift this shared
 * class exists to prevent.
 *
 * NORMALISED before storage: a caller may pass either shape a catalogue
 * value naturally comes in — a decoded array (a create's `$request->
 * validated()`) or a raw JSON-encoded string (an update's `getPrevious()`/
 * `getChanges()`, which read Eloquent's internal attribute representation
 * for a translatable/JSON column without decoding it). `normalize()`
 * decodes any top-level string that IS valid JSON, so a `created` row and an
 * `updated` row report the SAME field in the SAME shape — an auditor
 * comparing them should never have to notice which action produced which.
 */
final class PlatformAuditWriter
{
    public function __construct(
        private readonly AuditRedactor $redactor = new AuditRedactor,
    ) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        ?int $actorId,
        string $action,
        string $subjectType,
        int $subjectId,
        ?array $before,
        ?array $after,
    ): void {
        DB::table('audit_logs')->insert([
            'organization_id' => null,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => $before === null ? null : $this->encode($before),
            'after' => $after === null ? null : $this->encode($after),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode(
            $this->redactor->redact($this->normalize($payload)),
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Decode any top-level string value that is itself valid JSON.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalize(array $payload): array
    {
        return array_map(static function (mixed $value): mixed {
            if (! is_string($value)) {
                return $value;
            }

            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : $value;
        }, $payload);
    }
}
