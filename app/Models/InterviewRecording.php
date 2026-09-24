<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InterviewRecordingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * InterviewRecording (public-api step 6, G-01) — the audio-recording
 * pointer `GET /v1/interviews/{id}/recording` reads. One row per
 * participant enrolment; `object_key` is a PATH on the configured disk,
 * never a URL — see the owning migration's own docblock for why.
 *
 * No provider ingestion writes rows here yet (G-01, open): a live interview
 * has none, and the endpoint answers `404 recording_not_ready`. Step 9's
 * mock provider is the first writer.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $participant_id
 * @property string $object_key
 * @property string $format
 * @property int $duration_seconds
 * @property int $size_bytes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class InterviewRecording extends TenantModel
{
    /** @use HasFactory<InterviewRecordingFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'participant_id',
        'object_key',
        'format',
        'duration_seconds',
        'size_bytes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Participant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }
}
