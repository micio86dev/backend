<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InterviewSession;
use App\Models\Participant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for InterviewSession (C7a).
 *
 * Every foreign key is REQUIRED from the caller: a session belongs to a
 * participant, a project and a framework version that must agree on one
 * organization, and a factory that invented them independently would produce
 * rows no real flow can create.
 *
 * `organization_id` is not fillable on tenant-scoped models, so it is applied
 * with forceFill after creation.
 *
 * @extends Factory<InterviewSession>
 */
final class InterviewSessionFactory extends Factory
{
    protected $model = InterviewSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question_index' => 0,
            'competency_code' => 'COL',
            'provider' => 'heygen',
            'provider_session_ref' => 'sess_'.$this->faker->uuid(),
            'status' => 'ended',
            'ended_reason' => 'completed',
            'started_at' => now()->subMinutes(10),
            'ended_at' => now(),
        ];
    }

    /**
     * Derives `organization_id` from the participant when the caller did not
     * set it, mirroring ParticipantFactory: it is not fillable on a
     * tenant-scoped model, so it has to be forced on after making.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (InterviewSession $session): void {
            if ($session->participant_id && ! $session->organization_id) {
                $participant = Participant::find($session->participant_id);

                if ($participant !== null) {
                    $session->forceFill(['organization_id' => $participant->organization_id]);
                }
            }
        });
    }
}
