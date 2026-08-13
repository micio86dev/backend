<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IntegrityEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tenant-scoped IntegrityEvent model (C7a — Interview Session Mechanics).
 *
 * Stores one proctoring event per row. Events are validated at ingestion against
 * 13 canonical kinds from proctor-config.ts; unknown kinds are rejected with 422.
 *
 * Extends TenantModel:
 * - TenantScoped global scope: queries filtered by organization_id automatically.
 * - TenantScoped creating listener: organization_id stamped from resolver.
 *
 * payload: JSONB — flexible metadata (face coords, device info, etc.).
 * ts: client-supplied event occurrence time; cast as immutable datetime.
 *
 * REQ: IntegrityEvent tenant model (C7a)
 *
 * @property int $id
 * @property int $interview_session_id
 * @property int $organization_id
 * @property string $kind
 * @property array<string, mixed> $payload
 * @property Carbon $ts
 */
class IntegrityEvent extends TenantModel
{
    /** @use HasFactory<IntegrityEventFactory> */
    use HasFactory;

    /**
     * Mass-assignable attributes.
     *
     * organization_id excluded — stamped by TenantScoped.creating.
     *
     * @var list<string>
     */
    protected $fillable = [
        'interview_session_id',
        'kind',
        'payload',
        'ts',
    ];

    /**
     * Disable automatic timestamps (integrity events use only the ts field).
     */
    public $timestamps = false;

    /**
     * Attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'ts' => 'immutable_datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    /**
     * The interview session this integrity event belongs to.
     *
     * @return BelongsTo<InterviewSession, $this>
     */
    public function interviewSession(): BelongsTo
    {
        return $this->belongsTo(InterviewSession::class);
    }
}
