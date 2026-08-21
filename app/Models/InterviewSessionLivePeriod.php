<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InterviewSessionLivePeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stretch a session held a live provider session
 * (interview-session-started-at, D1).
 *
 * `ended_at === null` ⇔ the stretch is still live. `SessionLiveClock` is the
 * only writer of this model's rows — no other code opens or closes a
 * period.
 *
 * REQ: interview-session "Recorded duration is accumulated live time, not
 *      wall-clock span"
 *
 * @property int $id
 * @property int $organization_id
 * @property int $interview_session_id
 * @property string|null $provider_session_ref
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property string|null $closed_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class InterviewSessionLivePeriod extends TenantModel
{
    /** @use HasFactory<InterviewSessionLivePeriodFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'interview_session_id',
        'provider_session_ref',
        'started_at',
        'ended_at',
        'closed_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /**
     * The session this period belongs to.
     *
     * @return BelongsTo<InterviewSession, $this>
     */
    public function interviewSession(): BelongsTo
    {
        return $this->belongsTo(InterviewSession::class);
    }
}
