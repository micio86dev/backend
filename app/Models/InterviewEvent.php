<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Support\PublicApi\PubliclyIdentifiable;
use Database\Factories\InterviewEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * InterviewEvent (public-api step 5, G-34) — the timeline
 * `GET /v1/interviews/{id}/events` reads. A tenant-scoped, append-only log
 * of small, non-PII events on a `Participant` enrolment: `created`,
 * `invited`, `token_consumed` today; step 6 adds session/readiness events
 * from the existing candidate controllers and jobs.
 *
 * `data` MUST NEVER carry PII or transcript text (G-34) — every writer's own
 * responsibility; this model neither enforces nor inspects that.
 *
 * No `SoftDeletes`, no `updated_at`: append-only, same discipline
 * `App\Models\AuditLog` already applies for the identical reason (an event
 * once recorded is never revised).
 *
 * @property int $id
 * @property string $public_id BEAI Public API (`/v1`) external id, bare ULID — prefix `evt_`.
 * @property int $organization_id
 * @property int $participant_id
 * @property string $type
 * @property Carbon $occurred_at
 * @property array<string, mixed>|null $data
 * @property Carbon $created_at
 */
class InterviewEvent extends TenantModel implements PubliclyIdentifiable
{
    /** @use HasFactory<InterviewEventFactory> */
    use HasFactory, HasPublicId;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'participant_id',
        'type',
        'occurred_at',
        'data',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'data' => 'array',
        ];
    }

    public static function publicIdPrefix(): string
    {
        return 'evt_';
    }

    /**
     * @return BelongsTo<Participant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }
}
