<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tenant-scoped InterviewSnapshot model (C7a — Interview Session Mechanics).
 *
 * Stores the S3 reference for a JPEG proctoring snapshot taken during a session.
 * Images are validated (JPEG magic bytes + encoded size cap) before upload;
 * only the resulting s3_key is persisted here.
 *
 * s3_key scheme (server-generated): {org_id}/{participant_id}/{session_id}/{uuid}.jpg
 * taken_at: server-set — NOT client-supplied (prevents time forgery).
 *
 * Extends TenantModel:
 * - TenantScoped global scope: queries filtered by organization_id automatically.
 * - TenantScoped creating listener: organization_id stamped from resolver.
 *
 * No GDPR retention TTL in C7a — deferred to C13.
 *
 * REQ: InterviewSnapshot tenant model (C7a)
 *
 * @property int $id
 * @property int $interview_session_id
 * @property int $organization_id
 * @property string $s3_key
 * @property Carbon $taken_at
 */
class InterviewSnapshot extends TenantModel
{
    /**
     * Mass-assignable attributes.
     *
     * organization_id excluded — stamped by TenantScoped.creating.
     * taken_at excluded — stamped server-side, never client-supplied.
     *
     * @var list<string>
     */
    protected $fillable = [
        'interview_session_id',
        's3_key',
    ];

    /**
     * Disable automatic timestamps (snapshots track only taken_at).
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
            'taken_at' => 'immutable_datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    /**
     * The interview session this snapshot belongs to.
     *
     * @return BelongsTo<InterviewSession, $this>
     */
    public function interviewSession(): BelongsTo
    {
        return $this->belongsTo(InterviewSession::class);
    }
}
