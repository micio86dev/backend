<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Enums\WebhookSkipReason;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tenant-scoped webhook delivery audit-trail row (C10 — Webhooks Integration).
 *
 * One row per (organization_id, project_id, event_type, dedupe_key) — see design.md
 * D1/D2. The row — never the queue — is the single source of truth for attempt
 * accounting, idempotency, and the C11 read model. `payload` is frozen at INSERT time
 * and never regenerated after a later re-score.
 *
 * Extends TenantModel (S19) — organization_id is auto-stamped by TenantScoped.creating
 * and MUST NOT be in $fillable (prevents payload manipulation). Every write MUST happen
 * inside App\Support\Tenancy\TenantContextScope::runFor() (design.md D4) — with no
 * tenant context established, TenantScoped::creating throws
 * App\Exceptions\Tenancy\MissingTenantContextException (fail-closed, not a silent null
 * stamp).
 *
 * REQ: WebhookDelivery tenant model (C10 D1)
 *
 * @property int $id
 * @property int $organization_id
 * @property int $project_id
 * @property int $participant_id
 * @property string $delivery_id
 * @property WebhookEventType $event_type
 * @property string $dedupe_key
 * @property WebhookDeliveryStatus $status
 * @property WebhookSkipReason|null $skip_reason
 * @property string|null $target_url
 * @property array<string, mixed> $payload
 * @property string $payload_version
 * @property int $attempt_count
 * @property int $max_attempts
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $delivered_at
 * @property int|null $last_response_status
 * @property string|null $last_error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WebhookDelivery extends TenantModel
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    /**
     * organization_id intentionally excluded — stamped by TenantScoped.creating.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'participant_id',
        'delivery_id',
        'event_type',
        'dedupe_key',
        'status',
        'skip_reason',
        'target_url',
        'payload',
        'payload_version',
        'attempt_count',
        'max_attempts',
        'last_attempt_at',
        'next_attempt_at',
        'delivered_at',
        'last_response_status',
        'last_error',
    ];

    /**
     * Attribute casts.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'event_type' => WebhookEventType::class,
            'status' => WebhookDeliveryStatus::class,
            'skip_reason' => WebhookSkipReason::class,
            'payload' => 'array',
            'attempt_count' => 'integer',
            'max_attempts' => 'integer',
            'last_response_status' => 'integer',
            'last_attempt_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    /**
     * The project this delivery targets.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The participant this delivery is about.
     *
     * @return BelongsTo<Participant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }
}
