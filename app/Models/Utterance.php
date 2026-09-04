<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-scoped Utterance model (C7a — Interview Session Mechanics).
 *
 * Stores one line of transcript per turn (candidate or avatar).
 * HeyGen: live rows are REPLACED by authoritative server transcript at /end.
 * Tavus: best-effort live rows; not replaced at /end.
 *
 * Extends TenantModel:
 * - TenantScoped global scope: queries filtered by organization_id automatically.
 * - TenantScoped creating listener: organization_id stamped from resolver.
 *
 * ts: client-supplied timestamp of utterance; cast as immutable datetime.
 *
 * REQ: Utterance tenant model (C7a)
 *
 * @property int $id
 * @property int $interview_session_id
 * @property int $organization_id
 * @property string|null $provider_session_ref
 * @property string $speaker
 * @property string $text
 * @property CarbonImmutable $ts
 */
class Utterance extends TenantModel
{
    /**
     * Mass-assignable attributes.
     *
     * organization_id excluded — stamped by TenantScoped.creating.
     *
     * @var list<string>
     */
    protected $fillable = [
        'interview_session_id',
        // The provider session this turn was spoken on. A resumed competency
        // spans several, and a provider answers for one — so this is what tells
        // a fetch which stored rows it may replace and which belong to a stretch
        // it knows nothing about.
        'provider_session_ref',
        'speaker',
        'text',
        'ts',
    ];

    /**
     * Disable automatic timestamps (utterances use only the ts field).
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
            'ts' => 'immutable_datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    /**
     * The interview session this utterance belongs to.
     *
     * @return BelongsTo<InterviewSession, $this>
     */
    public function interviewSession(): BelongsTo
    {
        return $this->belongsTo(InterviewSession::class);
    }
}
